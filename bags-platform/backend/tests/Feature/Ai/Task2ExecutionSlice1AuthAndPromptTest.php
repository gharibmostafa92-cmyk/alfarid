<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Enums\Finance\TreasuryType;
use App\Enums\Orders\OrderStatus;
use App\Enums\Wholesale\QuotationStatus;
use App\Enums\Wholesale\WholesalePaymentNoticeStatus;
use App\Models\AiConversation;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Quotation;
use App\Models\Treasury;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\Ai\ToolRegistry;
use App\Services\Ai\Wholesale\WholesaleToolProvider;
use App\Services\Shared\SettingService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class Task2ExecutionSlice1AuthAndPromptTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        DB::table('settings')->whereIn('key', [
            'ai_enabled',
            'ai_wholesale_tools_enabled',
            'ai_finance_tools_enabled',
        ])->update(['value' => '1']);
        app(SettingService::class)->clearCache();
    }

    private function staff(array $perms, ?Branch $branch = null): User
    {
        $u = User::factory()->create([
            'branch_id' => $branch?->id,
        ]);
        foreach ($perms as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }

        return $u;
    }

    private function conv(User $u): AiConversation
    {
        return AiConversation::create(['user_id' => $u->id, 'model' => 'gemini-test']);
    }

    public function test_branch_restricted_staff_cannot_access_or_act_on_other_branch_notices_or_central_quotations(): void
    {
        $branchA = Branch::factory()->create(['is_active' => true]);
        $branchB = Branch::factory()->create(['is_active' => true]);

        $treasuryB = Treasury::create([
            'name' => 'خزينة فرع ب',
            'code' => 'TR-B',
            'type' => TreasuryType::MainSafe,
            'branch_id' => $branchB->id,
            'current_balance' => '1000.00',
            'is_active' => true,
        ]);

        $customer = Customer::factory()->create([
            'type' => CustomerType::Wholesale,
            'status' => CustomerStatus::Approved,
        ]);

        $orderA = Order::factory()->create([
            'customer_id' => $customer->id,
            'allocated_branch_id' => $branchA->id,
            'status' => OrderStatus::Confirmed,
        ]);
        $orderB = Order::factory()->create([
            'customer_id' => $customer->id,
            'allocated_branch_id' => $branchB->id,
            'status' => OrderStatus::Confirmed,
        ]);

        $noticeBase = [
            'payment_method' => 'bank_transfer',
            'bank_name' => 'CIB',
            'transfer_date' => now()->toDateString(),
            'receipt_file_path' => 'receipts/test.jpg',
        ];
        $noticeA = WholesalePaymentNotice::create($noticeBase + [
            'notice_number' => 'WPN-A-1',
            'customer_id' => $customer->id,
            'order_id' => $orderA->id,
            'reference_number' => 'REF-A-1',
            'amount' => '500.00',
            'status' => WholesalePaymentNoticeStatus::PendingReview,
            'match_score' => 98,
        ]);
        $noticeB = WholesalePaymentNotice::create($noticeBase + [
            'notice_number' => 'WPN-B-1',
            'customer_id' => $customer->id,
            'order_id' => $orderB->id,
            'reference_number' => 'REF-B-1',
            'amount' => '700.00',
            'status' => WholesalePaymentNoticeStatus::PendingReview,
            'match_score' => 98,
        ]);
        $approvedB = WholesalePaymentNotice::create($noticeBase + [
            'notice_number' => 'WPN-B-2',
            'customer_id' => $customer->id,
            'order_id' => $orderB->id,
            'treasury_id' => $treasuryB->id,
            'reference_number' => 'REF-B-2',
            'amount' => '300.00',
            'status' => WholesalePaymentNoticeStatus::Approved,
            'approved_at' => now(),
        ]);

        $quotation = Quotation::create([
            'quotation_number' => 'QT-1',
            'customer_id' => $customer->id,
            'status' => QuotationStatus::Requested,
        ]);

        $actor = $this->staff([
            'ai_agent.use',
            'ai_agent.execute',
            'ai_agent.wholesale',
            'payment_notices.view',
            'payment_notices.approve',
            'quotations.view',
            'quotations.price',
            'customers.wallet.view',
        ], $branchA);

        $registry = app(ToolRegistry::class);
        $conv = $this->conv($actor);

        // 1. GetPaymentNoticeTool on branch B notice -> denied
        $getOutcome = $registry->dispatch($actor, $conv, ['name' => 'get_payment_notice', 'args' => ['notice_id' => $noticeB->id]]);
        $this->assertSame('denied', $getOutcome->type);

        // 2. ApprovePaymentNoticeTool on branch B notice -> denied
        $appOutcome = $registry->dispatch($actor, $conv, ['name' => 'approve_payment_notice', 'args' => ['notice_id' => $noticeB->id]]);
        $this->assertSame('denied', $appOutcome->type);

        // 3. ApprovePaymentNoticeTool on branch A notice with branch B treasury -> denied
        $appCrossTreasury = $registry->dispatch($actor, $conv, [
            'name' => 'approve_payment_notice',
            'args' => ['notice_id' => $noticeA->id, 'treasury_id' => $treasuryB->id],
        ]);
        $this->assertSame('denied', $appCrossTreasury->type);

        // 4. RejectPaymentNoticeTool on branch B notice -> denied
        $rejOutcome = $registry->dispatch($actor, $conv, [
            'name' => 'reject_payment_notice',
            'args' => ['notice_id' => $noticeB->id, 'reason' => 'المبلغ غير مطابق'],
        ]);
        $this->assertSame('denied', $rejOutcome->type);

        // 5. ReversePaymentNoticeApprovalTool on branch B approved notice -> denied
        $revOutcome = $registry->dispatch($actor, $conv, [
            'name' => 'reverse_payment_notice_approval',
            'args' => ['notice_id' => $approvedB->id, 'reason' => 'عكس اختبار'],
        ]);
        $this->assertSame('denied', $revOutcome->type);

        // 6. BulkApproveMatchedNoticesTool must only include branch A notice
        $bulkNotices = $registry->dispatch($actor, $conv, ['name' => 'bulk_approve_matched_notices', 'args' => []]);
        $this->assertSame('proposal', $bulkNotices->type);
        $this->assertArrayHasKey('n'.$noticeA->id, $bulkNotices->uiBlock['items'] ? array_column($bulkNotices->uiBlock['items'], null, 'key') : []);
        $this->assertArrayNotHasKey('n'.$noticeB->id, array_column($bulkNotices->uiBlock['items'], null, 'key'));

        // 7. WholesaleInboxTool must only show branch A notice and hide central quotations
        $inbox = $registry->dispatch($actor, $conv, ['name' => 'wholesale_inbox', 'args' => []]);
        $this->assertSame('data', $inbox->type);
        $noticeIds = array_column($inbox->data['notices_waiting'], 'id');
        $this->assertContains($noticeA->id, $noticeIds);
        $this->assertNotContains($noticeB->id, $noticeIds);
        $this->assertSame([], $inbox->data['quotations_waiting']);

        // 8. PriceQuotationTool without central access -> denied
        $priceOutcome = $registry->dispatch($actor, $conv, ['name' => 'price_quotation', 'args' => ['quotation_id' => $quotation->id]]);
        $this->assertSame('denied', $priceOutcome->type);

        // 9. BulkPriceQuotationsTool without central access -> denied
        $bulkPriceOutcome = $registry->dispatch($actor, $conv, ['name' => 'bulk_price_quotations', 'args' => ['discount_percent' => 10]]);
        $this->assertSame('denied', $bulkPriceOutcome->type);

        // 10. Central customer balances and quotations reject direct-ID access from branch staff.
        foreach ([
            ['name' => 'explain_outstanding', 'args' => ['customer_id' => $customer->id]],
            ['name' => 'customer_account_360', 'args' => ['customer_id' => $customer->id]],
            ['name' => 'customer_statement', 'args' => ['customer_id' => $customer->id]],
            ['name' => 'customer_wallet', 'args' => ['customer_id' => $customer->id]],
            ['name' => 'get_quotation', 'args' => ['quotation_id' => $quotation->id]],
            ['name' => 'suggest_quote_pricing', 'args' => ['quotation_id' => $quotation->id]],
            ['name' => 'create_quotation_for_customer', 'args' => ['customer_id' => $customer->id, 'text' => '1 SKU']],
            ['name' => 'expire_quotation', 'args' => ['quotation_id' => $quotation->id]],
            ['name' => 'extend_quotation_validity', 'args' => ['quotation_id' => $quotation->id, 'days' => 7]],
            ['name' => 'reconcile_bank_statement', 'args' => ['rows' => [['amount' => 100, 'reference' => 'REF-CENTRAL', 'date' => '2026-11-18', 'description' => 'تحويل']]]],
        ] as $call) {
            $outcome = $registry->dispatch($actor, $conv, $call);
            $this->assertSame('denied', $outcome->type, $call['name'].' must require central access.');
        }
    }

    public function test_create_expenses_bulk_denies_cross_branch_expense_with_central_treasury(): void
    {
        $branchA = Branch::factory()->create(['is_active' => true]);
        $branchB = Branch::factory()->create(['is_active' => true]);

        $centralTreasury = Treasury::create([
            'name' => 'حساب بنكي مركزي',
            'code' => 'TR-C',
            'type' => TreasuryType::BankAccount,
            'branch_id' => null,
            'current_balance' => '50000.00',
            'is_active' => true,
        ]);

        $actor = $this->staff([
            'ai_agent.use',
            'ai_agent.execute',
            'ai_agent.finance',
            'expenses.create',
        ], $branchA);

        $outcome = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'create_expenses_bulk',
            'args' => [
                'rows' => [[
                    'treasury_id' => $centralTreasury->id,
                    'branch_id' => $branchB->id,
                    'category' => 'نقل',
                    'amount' => '150.00',
                ]],
            ],
        ]);

        $this->assertSame('denied', $outcome->type);
    }

    public function test_wholesale_system_prompt_reflects_execute_permission(): void
    {
        $provider = app(WholesaleToolProvider::class);

        $executor = $this->staff(['ai_agent.use', 'ai_agent.wholesale', 'ai_agent.execute', 'quotations.view']);
        $executorPrompt = $provider->systemPromptSection($executor);
        $this->assertNotNull($executorPrompt);
        $this->assertStringNotContainsString('مفيش أداة كتابة متاحة دلوقتي', $executorPrompt);
        $this->assertStringContainsString('تأكيد', $executorPrompt);

        $reader = $this->staff(['ai_agent.use', 'ai_agent.wholesale', 'quotations.view']);
        $readerPrompt = $provider->systemPromptSection($reader);
        $this->assertNotNull($readerPrompt);
        $this->assertStringContainsString('قراءة فقط', $readerPrompt);
    }
}
