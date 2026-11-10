<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Finance\TreasuryType;
use App\Models\AiConversation;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Treasury;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Services\Ai\Finance\FinanceToolProvider;
use App\Services\Ai\ToolRegistry;
use App\Services\Pos\ExpenseService;
use App\Services\Shared\SettingService;
use App\Services\Pos\TreasuryService;
use App\Services\Finance\WalletService;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\FinancialPermissionSeeder;
use Database\Seeders\SettingSeeder;
use App\Enums\StaffRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinanceReadToolsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(AiAgentPermissionSeeder::class);
        $this->seed(FinancialPermissionSeeder::class);
        DB::table('settings')->whereIn('key', [
            'ai_enabled',
            'ai_finance_tools_enabled',
            'ai_finance_wallet_tools_enabled',
            'ai_action_ttl_minutes',
        ])->update(['value' => '1']);
        app(SettingService::class)->clearCache();
    }

    private function staff(array $perms, ?int $branchId = null): User
    {
        $u = User::factory()->create(['branch_id' => $branchId]);
        foreach ([...$perms, 'ai_agent.finance'] as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }

        return $u;
    }

    private function conv(User $u): AiConversation
    {
        return AiConversation::create(['user_id' => $u->id, 'model' => 'gemini-test']);
    }

    private function treasury(Branch $branch, array $attributes = []): Treasury
    {
        return Treasury::create([
            'name' => 'خزينة '.Str::random(6), 'code' => 'SAFE-'.Str::uuid(),
            'branch_id' => $branch->id, 'type' => TreasuryType::MainSafe,
            'current_balance' => '0.00', 'is_active' => true,
            ...$attributes,
        ]);
    }

    /** @return array{admin: User, branchA: Branch, branchB: Branch} */
    private function scaffold(): array
    {
        $admin = $this->staff(['ai_agent.use', 'treasuries.view', 'treasury_transfers.view', 'treasury_transfers.create', 'treasury_transfers.approve', 'expenses.view', 'expenses.create', 'finance.reports.view', 'customers.wallet.view', 'orders.view']);
        $admin->assignRole(Role::findOrCreate(StaffRole::SuperAdmin->value, 'staff'));
        $branchA = Branch::factory()->create(['is_active' => true]);
        $branchB = Branch::factory()->create(['is_active' => true]);

        return compact('admin', 'branchA', 'branchB');
    }

    public function test_all_twelve_read_tools_are_registered(): void
    {
        $defs = app(ToolRegistry::class)->definitions();
        foreach (['list_treasuries', 'get_treasury', 'treasury_ledger', 'explain_treasury_balance', 'cash_position', 'list_treasury_transfers', 'get_treasury_transfer', 'my_approval_queue', 'list_expenses', 'expense_summary', 'customer_wallet', 'explain_order_money'] as $tool) {
            $this->assertArrayHasKey($tool, $defs, $tool);
            $this->assertInstanceOf(FinanceToolProvider::class, $defs[$tool]['provider'], $tool);
            $this->assertSame('read', $defs[$tool]['kind']->value, $tool);
            $this->assertSame('low', $defs[$tool]['risk'], $tool);
        }
    }

    public function test_read_tool_denied_without_permission(): void
    {
        ['admin' => $admin, 'branchA' => $branchA] = $this->scaffold();
        $this->treasury($branchA);

        $outsider = $this->staff(['ai_agent.use']);
        $outcome = app(ToolRegistry::class)->dispatch($outsider, $this->conv($outsider), [
            'name' => 'list_treasuries', 'args' => [],
        ]);

        $this->assertSame('denied', $outcome->type);
    }

    public function test_branch_restricted_staff_only_sees_own_treasuries(): void
    {
        ['admin' => $admin, 'branchA' => $branchA, 'branchB' => $branchB] = $this->scaffold();
        $this->treasury($branchA, ['name' => 'خزينة المعادي']);
        $this->treasury($branchB, ['name' => 'خزينة الدقي']);

        $staff = $this->staff(['ai_agent.use', 'treasuries.view'], $branchA->id);
        $outcome = app(ToolRegistry::class)->dispatch($staff, $this->conv($staff), [
            'name' => 'list_treasuries', 'args' => [],
        ]);

        $this->assertSame('data', $outcome->type);
        $this->assertCount(1, $outcome->data['treasuries']);
        $this->assertSame('خزينة المعادي', $outcome->data['treasuries'][0]['name']['untrusted_text']);

        $foreign = app(ToolRegistry::class)->dispatch($staff, $this->conv($staff), [
            'name' => 'list_treasuries', 'args' => ['branch_id' => $branchB->id],
        ]);
        $this->assertSame('denied', $foreign->type);
    }

    public function test_get_treasury_reports_delete_blockers(): void
    {
        ['admin' => $admin, 'branchA' => $branchA] = $this->scaffold();
        $empty = $this->treasury($branchA);
        $used = $this->treasury($branchA);
        app(TreasuryService::class)->record([
            'treasury_id' => $used->id, 'type' => \App\Enums\TreasuryTransactionType::OpeningBalance,
            'amount' => '1000.00', 'created_by' => $admin->id,
        ]);

        $fresh = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'get_treasury', 'args' => ['treasury_id' => $empty->id],
        ]);
        $this->assertSame('data', $fresh->type);
        $this->assertTrue($fresh->data['treasury']['can_delete']);
        $this->assertSame('treasury_card', $fresh->uiBlock['type']);

        $blocked = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'get_treasury', 'args' => ['treasury_id' => $used->id],
        ]);
        $this->assertFalse($blocked->data['treasury']['can_delete']);
        $this->assertNotEmpty($blocked->data['treasury']['delete_blockers']);
        $this->assertSame(1000.0, $blocked->data['treasury']['current_balance']);
    }

    public function test_treasury_ledger_filters_and_cursor(): void
    {
        ['admin' => $admin, 'branchA' => $branchA] = $this->scaffold();
        $treasury = $this->treasury($branchA);
        $svc = app(TreasuryService::class);
        $svc->record(['treasury_id' => $treasury->id, 'type' => \App\Enums\TreasuryTransactionType::OpeningBalance, 'amount' => '1000.00', 'created_by' => $admin->id]);
        $svc->record(['treasury_id' => $treasury->id, 'type' => \App\Enums\TreasuryTransactionType::Expense, 'amount' => '-200.00', 'created_by' => $admin->id, 'reference_type' => Expense::class, 'reference_id' => 999]);

        $filtered = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'treasury_ledger', 'args' => ['treasury_id' => $treasury->id, 'type' => 'expense'],
        ]);
        $this->assertSame('data', $filtered->type);
        $this->assertCount(1, $filtered->data['movements']);
        $this->assertSame('مصروف', $filtered->data['movements'][0]['type_label']);
        $this->assertFalse($filtered->data['has_more']);

        $first = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'treasury_ledger', 'args' => ['treasury_id' => $treasury->id, 'limit' => 1],
        ]);
        $this->assertTrue($first->data['has_more']);
        $this->assertNotNull($first->data['next_cursor']);

        $second = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'treasury_ledger', 'args' => ['treasury_id' => $treasury->id, 'limit' => 1, 'cursor' => $first->data['next_cursor']],
        ]);
        $this->assertCount(1, $second->data['movements']);
        $this->assertNotSame($first->data['movements'][0]['id'], $second->data['movements'][0]['id']);
    }

    public function test_explain_treasury_balance_sums_match_current_balance(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-11 00:30:00', 'Africa/Cairo'));
        ['admin' => $admin, 'branchA' => $branchA] = $this->scaffold();
        $treasury = $this->treasury($branchA);
        $svc = app(TreasuryService::class);
        $svc->record(['treasury_id' => $treasury->id, 'type' => \App\Enums\TreasuryTransactionType::OpeningBalance, 'amount' => '1000.00', 'created_by' => $admin->id]);
        $svc->record(['treasury_id' => $treasury->id, 'type' => \App\Enums\TreasuryTransactionType::Expense, 'amount' => '-200.00', 'created_by' => $admin->id]);

        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'explain_treasury_balance',
            'args' => ['treasury_id' => $treasury->id, 'from_date' => '2026-11-11', 'to_date' => '2026-11-11'],
        ]);

        $this->assertSame('data', $outcome->type);
        $this->assertSame(0.0, $outcome->data['opening_balance']);
        $this->assertSame(800.0, $outcome->data['ending_balance']);
        $this->assertTrue($outcome->data['matches_current']);
        $this->assertSame(0.0, $outcome->data['difference']);
        $this->assertCount(2, $outcome->uiBlock['groups']);
        $this->assertSame('balance_story', $outcome->uiBlock['type']);
    }

    public function test_cash_position_keeps_balances_in_the_card_when_model_sharing_is_off(): void
    {
        ['admin' => $admin, 'branchA' => $branch] = $this->scaffold();
        $this->treasury($branch, ['current_balance' => '1000.00']);
        DB::table('settings')->where('key', 'ai_finance_share_balances_with_model')->update(['value' => '0']);
        app(SettingService::class)->clearCache();

        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'cash_position', 'args' => ['branch_id' => $branch->id],
        ]);
        $this->assertTrue($outcome->data['balances_hidden_from_model']);
        $this->assertNull($outcome->data['totals']['cash']);
        $this->assertNull($outcome->data['branches'][0]['projected']);
        $this->assertSame(1000.0, $outcome->uiBlock['totals']['cash']);
        $this->assertSame(1000.0, $outcome->uiBlock['branches'][0]['projected']);
    }

    public function test_cash_position_projects_pending_transfers(): void
    {
        ['admin' => $admin, 'branchA' => $branchA, 'branchB' => $branchB] = $this->scaffold();
        $from = $this->treasury($branchA, ['current_balance' => '1000.00']);
        $to = $this->treasury($branchB, ['current_balance' => '200.00']);
        app(TreasuryService::class)->createTransfer([
            'from_treasury_id' => $from->id, 'to_treasury_id' => $to->id, 'amount' => '300.00',
        ], $admin->id);

        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'cash_position', 'args' => [],
        ]);

        $this->assertSame('data', $outcome->type);
        $this->assertSame('cash_position', $outcome->uiBlock['type']);
        $byBranch = collect($outcome->data['branches'])->keyBy('branch');
        $this->assertSame(700.0, $byBranch[$branchA->name]['projected']);
        $this->assertSame(500.0, $byBranch[$branchB->name]['projected']);
    }

    public function test_list_transfers_matches_either_side(): void
    {
        ['admin' => $admin, 'branchA' => $branchA, 'branchB' => $branchB] = $this->scaffold();
        $from = $this->treasury($branchA);
        $to = $this->treasury($branchB);
        app(TreasuryService::class)->createTransfer([
            'from_treasury_id' => $from->id, 'to_treasury_id' => $to->id, 'amount' => '100.00',
        ], $admin->id);

        // Destination-side filter — a capability the REST index lacks.
        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'list_treasury_transfers', 'args' => ['treasury_id' => $to->id],
        ]);

        $this->assertSame('data', $outcome->type);
        $this->assertCount(1, $outcome->data['transfers']);
        $this->assertSame('pending', $outcome->data['transfers'][0]['status']);
    }

    public function test_get_transfer_timeline_and_self_approval_flag(): void
    {
        ['admin' => $admin, 'branchA' => $branchA] = $this->scaffold();
        $from = $this->treasury($branchA, ['current_balance' => '1000.00']);
        $to = $this->treasury($branchA);
        $transfer = app(TreasuryService::class)->createTransfer([
            'from_treasury_id' => $from->id, 'to_treasury_id' => $to->id, 'amount' => '100.00',
        ], $admin->id);

        $asCreator = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'get_treasury_transfer', 'args' => ['transfer_id' => $transfer->id],
        ]);
        $this->assertSame('data', $asCreator->type);
        $this->assertFalse($asCreator->data['transfer']['can_approve']);
        $this->assertSame('transfer_timeline', $asCreator->uiBlock['type']);
        $this->assertSame('created', $asCreator->uiBlock['stages'][0]['stage']);

        $approver = $this->staff(['ai_agent.use', 'treasury_transfers.view', 'treasury_transfers.approve'], $branchA->id);
        $asApprover = app(ToolRegistry::class)->dispatch($approver, $this->conv($approver), [
            'name' => 'get_treasury_transfer', 'args' => ['transfer_id' => $transfer->id],
        ]);
        $this->assertTrue($asApprover->data['transfer']['can_approve']);

        app(TreasuryService::class)->approveTransfer($transfer, $approver->id);
        $approved = app(ToolRegistry::class)->dispatch($approver, $this->conv($approver), [
            'name' => 'get_treasury_transfer', 'args' => ['transfer_id' => $transfer->id],
        ]);
        $this->assertSame('approved', $approved->data['transfer']['status']);
        $this->assertSame('approved', $approved->uiBlock['stages'][1]['stage']);
        $this->assertTrue($approved->uiBlock['stages'][1]['done']);
        $this->assertSame(900.0, $approved->data['transfer']['from_balance']);
    }

    public function test_approval_queue_excludes_own_and_warns_on_shortfall(): void
    {
        ['admin' => $admin, 'branchA' => $branchA] = $this->scaffold();
        $from = $this->treasury($branchA, ['current_balance' => '100.00']);
        $to = $this->treasury($branchA);
        app(TreasuryService::class)->createTransfer([
            'from_treasury_id' => $from->id, 'to_treasury_id' => $to->id, 'amount' => '500.00',
        ], $admin->id);

        $mine = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'my_approval_queue', 'args' => [],
        ]);
        $this->assertSame('data', $mine->type);
        $this->assertCount(0, $mine->data['queue']);

        $approver = $this->staff(['ai_agent.use', 'treasury_transfers.approve'], $branchA->id);
        $queue = app(ToolRegistry::class)->dispatch($approver, $this->conv($approver), [
            'name' => 'my_approval_queue', 'args' => [],
        ]);
        $this->assertCount(1, $queue->data['queue']);
        $this->assertFalse($queue->data['queue'][0]['source_sufficient']);
        $this->assertNotNull($queue->data['queue'][0]['warning']);
    }

    public function test_list_expenses_branch_scope_and_void_filter(): void
    {
        ['admin' => $admin, 'branchA' => $branchA, 'branchB' => $branchB] = $this->scaffold();
        $treasuryA = $this->treasury($branchA, ['current_balance' => '5000.00']);
        $treasuryB = $this->treasury($branchB, ['current_balance' => '5000.00']);
        $svc = app(ExpenseService::class);
        $key = fn () => 'test-'.Str::uuid()->toString();
        $svc->create(['branch_id' => $branchA->id, 'treasury_id' => $treasuryA->id, 'category' => 'كهرباء', 'amount' => '100.00', 'expense_date' => now()->toDateString()], $admin, $key());
        $doomed = $svc->create(['branch_id' => $branchA->id, 'treasury_id' => $treasuryA->id, 'category' => 'مياه', 'amount' => '50.00', 'expense_date' => now()->toDateString()], $admin, $key());
        $svc->void($doomed, 'خطأ', $admin);
        $svc->create(['branch_id' => $branchB->id, 'treasury_id' => $treasuryB->id, 'category' => 'كهرباء', 'amount' => '70.00', 'expense_date' => now()->toDateString()], $admin, $key());

        $staff = $this->staff(['ai_agent.use', 'expenses.view'], $branchA->id);
        $all = app(ToolRegistry::class)->dispatch($staff, $this->conv($staff), [
            'name' => 'list_expenses', 'args' => [],
        ]);
        $this->assertCount(2, $all->data['expenses']);

        $active = app(ToolRegistry::class)->dispatch($staff, $this->conv($staff), [
            'name' => 'list_expenses', 'args' => ['voided' => false],
        ]);
        $this->assertCount(1, $active->data['expenses']);
        $this->assertSame('كهرباء', $active->data['expenses'][0]['category']['untrusted_text']);
    }

    public function test_expense_summary_compares_with_previous_period_and_top_categories(): void
    {
        ['admin' => $admin, 'branchA' => $branchA] = $this->scaffold();
        $treasuryA = $this->treasury($branchA, ['current_balance' => '5000.00']);
        $svc = app(ExpenseService::class);
        $key = fn () => 'test-'.Str::uuid()->toString();
        $from = now()->subDays(10)->toDateString();
        $to = now()->toDateString();
        $svc->create(['branch_id' => $branchA->id, 'treasury_id' => $treasuryA->id, 'category' => 'كهرباء', 'amount' => '100.00', 'expense_date' => $to], $admin, $key());
        $svc->create(['branch_id' => $branchA->id, 'treasury_id' => $treasuryA->id, 'category' => 'كهرباء', 'amount' => '50.00', 'expense_date' => now()->subDays(15)->toDateString()], $admin, $key());

        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'expense_summary', 'args' => ['group_by' => 'category', 'from_date' => $from, 'to_date' => $to],
        ]);

        $this->assertSame('data', $outcome->type);
        $this->assertCount(1, $outcome->data['groups']);
        $this->assertSame(100.0, $outcome->data['groups'][0]['total']);
        $this->assertSame(50.0, $outcome->data['groups'][0]['previous_total']);
        $this->assertSame(100.0, $outcome->data['groups'][0]['change_pct']);
        $this->assertSame('كهرباء', $outcome->data['top_categories'][0]['category']['untrusted_text']);
    }

    public function test_customer_wallet_shows_translated_types_and_references(): void
    {
        ['admin' => $admin] = $this->scaffold();
        $customer = Customer::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id, 'payment_method' => 'wallet', 'payment_status' => 'paid', 'grand_total' => 500]);
        $wallet = app(WalletService::class);
        $wallet->record($customer->id, 1000.0, 'topup', 'manual', $customer->id, $admin->id);
        $wallet->record($customer->id, -500.0, 'checkout_payment', 'order', $order->id);

        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'customer_wallet', 'args' => ['customer_id' => $customer->id],
        ]);

        $this->assertSame('data', $outcome->type);
        $this->assertSame('wallet_card', $outcome->uiBlock['type']);
        $this->assertSame(500.0, $outcome->data['current_balance']);
        $this->assertCount(2, $outcome->data['transactions']);
        $latest = $outcome->data['transactions'][0];
        $this->assertSame('دفع طلب', $latest['type_label']);
        $this->assertSame('طلب #'.$order->order_number, $latest['reference_label']);
    }

    public function test_order_money_requires_both_permissions_and_respects_branch_scope(): void
    {
        ['branchA' => $branchA, 'branchB' => $branchB] = $this->scaffold();
        $order = Order::factory()->create(['allocated_branch_id' => $branchA->id]);
        $call = ['name' => 'explain_order_money', 'args' => ['order_id' => $order->id]];
        foreach ([
            $this->staff(['ai_agent.use', 'orders.view'], $branchA->id),
            $this->staff(['ai_agent.use', 'treasuries.view'], $branchA->id),
            $this->staff(['ai_agent.use', 'orders.view', 'treasuries.view'], $branchB->id),
        ] as $actor) {
            $outcome = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), $call);
            $this->assertSame('denied', $outcome->type);
        }
        $actor = $this->staff(['ai_agent.use', 'orders.view', 'treasuries.view'], $branchA->id);
        $this->assertSame('data', app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), $call)->type);

        $actor->revokePermissionTo('ai_agent.finance');
        $this->assertSame('denied', app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), $call)->type);
    }

    public function test_explain_order_money_wallet_payment_sentence(): void
    {
        ['admin' => $admin] = $this->scaffold();
        $customer = Customer::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id, 'payment_method' => 'wallet', 'payment_status' => 'paid', 'grand_total' => 500]);
        $wallet = app(WalletService::class);
        $wallet->record($customer->id, 1000.0, 'topup', 'manual', $customer->id, $admin->id);
        $wallet->record($customer->id, -500.0, 'checkout_payment', 'order', $order->id);

        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'explain_order_money', 'args' => ['order_id' => $order->id],
        ]);

        $this->assertSame('data', $outcome->type);
        $this->assertSame('money_trail', $outcome->uiBlock['type']);
        $this->assertCount(1, $outcome->data['wallet']);
        $this->assertSame([], $outcome->data['treasury']);
        $this->assertStringContainsString('ما دخلتش درج', $outcome->data['explanation']['untrusted_text']);
        $this->assertSame([], $outcome->data['consistency_warnings']);
    }

    public function test_explain_order_money_warns_on_paid_order_without_movements(): void
    {
        ['admin' => $admin] = $this->scaffold();
        $order = Order::factory()->create(['payment_method' => 'cash', 'payment_status' => 'paid', 'grand_total' => 250]);

        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'explain_order_money', 'args' => ['order_id' => $order->id],
        ]);

        $this->assertSame('data', $outcome->type);
        $this->assertNotEmpty($outcome->data['consistency_warnings']);
        $this->assertStringContainsString('لا توجد أي حركة مالية', $outcome->data['consistency_warnings'][0]);
    }

    public function test_large_results_truncate_with_narrowing_hint(): void
    {
        ['admin' => $admin, 'branchA' => $branchA] = $this->scaffold();
        for ($i = 0; $i < 30; $i++) {
            $this->treasury($branchA);
        }
        DB::table('settings')->where('key', 'ai_tool_result_max_chars')->update(['value' => '1024']);
        app(SettingService::class)->clearCache();

        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'list_treasuries', 'args' => ['limit' => 50],
        ]);

        $this->assertSame('data', $outcome->type);
        $this->assertTrue($outcome->data['truncated']);
        $this->assertSame('ضيّق البحث', $outcome->data['message']);
    }
}
