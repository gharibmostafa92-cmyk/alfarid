<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Finance\TreasuryTransferStatus;
use App\Enums\Finance\TreasuryType;
use App\Enums\StaffRole;
use App\Exceptions\BusinessException;
use App\Models\AiConversation;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Treasury;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\Ai\Finance\FinanceWriteGuards;
use App\Services\Ai\ToolRegistry;
use App\Services\Pos\ExpenseService;
use App\Services\Pos\TreasuryService;
use App\Services\Shared\SettingService;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\FinancialPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Task8Slice2TreasuryAndGuardsTest extends TestCase
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
            'ai_action_ttl_minutes',
        ])->update(['value' => '1']);
        app(SettingService::class)->clearCache();
    }

    private function makeStaff(?int $branchId, array $permissions, bool $allBranches = false): User
    {
        $user = User::factory()->create(['branch_id' => $branchId]);
        foreach ($permissions as $perm) {
            $user->givePermissionTo(Permission::findOrCreate($perm, 'staff'));
        }
        if ($allBranches) {
            $user->givePermissionTo(Permission::findOrCreate('inventory.all_branches', 'staff'));
            $user->assignRole(Role::findOrCreate(StaffRole::SuperAdmin->value, 'staff'));
        }

        return $user;
    }

    private function makeTreasury(?Branch $branch, string $type, string $balance = '0.00', string $name = 'Safe'): Treasury
    {
        return tap(Treasury::create([
            'name' => $name,
            'code' => 'T8-'.Str::uuid(),
            'branch_id' => $branch?->id,
            'type' => $type,
            'is_active' => true,
        ]), fn (Treasury $t) => $t->forceFill(['current_balance' => $balance])->save())->fresh();
    }

    public function test_f04_transfer_index_blocks_cross_branch_filter_probe_and_hides_balances_and_staff_pii(): void
    {
        $branchA = Branch::factory()->create(['is_active' => true]);
        $branchB = Branch::factory()->create(['is_active' => true]);
        $centralAdmin = $this->makeStaff(null, [
            'treasury_transfers.view',
            'treasury_transfers.create',
            'treasury_transfers.approve',
            'expenses.view',
            'expenses.create',
        ], true);
        $branchBUser = $this->makeStaff($branchB->id, [
            'treasury_transfers.view',
            'expenses.view',
        ], false);

        $safeA = $this->makeTreasury($branchA, TreasuryType::MainSafe->value, '50000.00', 'Central-A');
        $safeB = $this->makeTreasury($branchB, TreasuryType::MainSafe->value, '1000.00', 'Safe-B');

        $transfer = TreasuryTransfer::create([
            'transfer_number' => 'TT-XBR-1',
            'from_treasury_id' => $safeA->id,
            'to_treasury_id' => $safeB->id,
            'amount' => '500.00',
            'status' => TreasuryTransferStatus::Pending,
            'created_by' => $centralAdmin->id,
        ]);

        // Cross-branch filter probe by Branch B user must fail with 403.
        Auth::forgetGuards();
        $branchBToken = $branchBUser->createToken('t')->plainTextToken;
        $this->withToken($branchBToken)
            ->getJson("/api/v1/admin/treasury-transfers?from_treasury_id={$safeA->id}")
            ->assertForbidden();

        // Authorized list and show must NOT expose from_treasury/to_treasury current_balance or creator email.
        $listRes = $this->withToken($branchBToken)
            ->getJson('/api/v1/admin/treasury-transfers')
            ->assertOk();
        $row = $listRes->json('data.0');
        $this->assertArrayNotHasKey('current_balance', $row['from_treasury'] ?? []);
        $this->assertArrayNotHasKey('current_balance', $row['to_treasury'] ?? []);
        $this->assertArrayNotHasKey('email', $row['creator'] ?? []);

        $showRes = $this->withToken($branchBToken)
            ->getJson("/api/v1/admin/treasury-transfers/{$transfer->id}")
            ->assertOk();
        $this->assertArrayNotHasKey('current_balance', $showRes->json('data.from_treasury') ?? []);
        $this->assertArrayNotHasKey('current_balance', $showRes->json('data.to_treasury') ?? []);
        $this->assertArrayNotHasKey('email', $showRes->json('data.creator') ?? []);

        // Expenses index/show must also project minimal treasury & creator fields.
        $expense = Expense::create([
            'expense_number' => 'EXP-PROJ-1',
            'branch_id' => $branchB->id,
            'treasury_id' => $safeB->id,
            'category' => 'نثريات',
            'amount' => '10.00',
            'expense_date' => now()->toDateString(),
            'created_by' => $centralAdmin->id,
        ]);

        $expRes = $this->withToken($branchBToken)
            ->getJson("/api/v1/admin/expenses/{$expense->id}")
            ->assertOk();
        $this->assertArrayNotHasKey('current_balance', $expRes->json('data.treasury') ?? []);
        $this->assertArrayNotHasKey('email', $expRes->json('data.creator') ?? []);
    }

    public function test_f06_delete_treasury_blocked_by_expense_or_payment_notice_without_500(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $admin = $this->makeStaff($branch->id, ['ai_agent.use', 'ai_agent.finance', 'treasuries.delete'], true);
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);

        $treasuryWithExpense = $this->makeTreasury($branch, TreasuryType::MainSafe->value, '0.00', 'Safe Exp');
        Expense::create([
            'expense_number' => 'EXP-BLK-1',
            'branch_id' => $branch->id,
            'treasury_id' => $treasuryWithExpense->id,
            'category' => 'إيجار',
            'amount' => '100.00',
            'expense_date' => now()->toDateString(),
            'created_by' => $admin->id,
        ]);

        $out = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'delete_treasury',
            'args' => ['treasury_id' => $treasuryWithExpense->id],
        ]);
        $this->assertSame('denied', $out->type);

        try {
            app(TreasuryService::class)->deleteAccount($treasuryWithExpense, $admin);
            $this->fail('Expected BusinessException when deleting treasury referenced by expense.');
        } catch (BusinessException) {
        }

        $treasuryWithNotice = $this->makeTreasury($branch, TreasuryType::BankAccount->value, '0.00', 'Bank Notice');
        $customer = Customer::factory()->create();
        WholesalePaymentNotice::create([
            'notice_number' => 'WPN-BLK-1',
            'customer_id' => $customer->id,
            'bank_name' => 'CIB',
            'amount' => '250.00',
            'payment_method' => 'bank_transfer',
            'reference_number' => 'REF-BLK-1',
            'transfer_date' => now()->toDateString(),
            'receipt_file_path' => 'wholesale/notices/receipt.jpg',
            'treasury_id' => $treasuryWithNotice->id,
            'status' => 'pending_review',
        ]);

        $outNotice = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'delete_treasury',
            'args' => ['treasury_id' => $treasuryWithNotice->id],
        ]);
        $this->assertSame('denied', $outNotice->type);

        try {
            app(TreasuryService::class)->deleteAccount($treasuryWithNotice, $admin);
            $this->fail('Expected BusinessException when deleting treasury referenced by wholesale payment notice.');
        } catch (BusinessException) {
        }
    }

    public function test_f07_create_transfer_rejects_destination_overflow(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $admin = $this->makeStaff($branch->id, ['treasury_transfers.create'], true);
        $from = $this->makeTreasury($branch, TreasuryType::MainSafe->value, '500.00', 'From Safe');
        $to = $this->makeTreasury($branch, TreasuryType::MainSafe->value, '999999999900.00', 'Full Safe');

        $this->expectException(BusinessException::class);
        app(TreasuryService::class)->createTransfer([
            'from_treasury_id' => $from->id,
            'to_treasury_id' => $to->id,
            'amount' => '200.00',
        ], $admin->id);
    }

    public function test_f11_suggest_top_up_avoids_float_shortfall_exclusion_and_ledger_version_normalizes_decimal(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $poor = $this->makeTreasury($branch, TreasuryType::MainSafe->value, '80.11', 'Poor');
        $donor = $this->makeTreasury($branch, TreasuryType::MainSafe->value, '19.99', 'Exact Donor');

        // Exact IEEE-754 float overshoot (19.990000000000009) must still match donor at 19.99.
        $shortfall = 19.990000000000009;
        $suggestion = app(FinanceWriteGuards::class)->suggestTopUp($branch->id, $poor->id, $shortfall, $poor->name);

        $this->assertNotNull($suggestion);
        $this->assertSame($donor->id, $suggestion['treasury_id']);

        $fresh = new Treasury;
        $fresh->id = $poor->id;
        $fresh->forceFill(['current_balance' => '0']);
        $scaled = new Treasury;
        $scaled->id = $poor->id;
        $scaled->forceFill(['current_balance' => '0.00']);

        $this->assertSame(
            FinanceWriteGuards::ledgerVersion($fresh),
            FinanceWriteGuards::ledgerVersion($scaled),
        );
    }

    public function test_f12_expense_attachment_path_blocks_traversal_and_unauthorized_prefix(): void
    {
        Storage::fake('ai_private');
        Storage::disk('ai_private')->put('ai-chat/secret.pdf', 'SECRET');
        Storage::disk('ai_private')->put('expense-receipts/99/receipt.pdf', 'OK');

        $branch = Branch::factory()->create(['is_active' => true]);
        $admin = $this->makeStaff($branch->id, ['expenses.create', 'expenses.view'], true);
        $safe = $this->makeTreasury($branch, TreasuryType::MainSafe->value, '5000.00', 'Safe');

        try {
            app(ExpenseService::class)->create([
                'branch_id' => $branch->id,
                'treasury_id' => $safe->id,
                'category' => 'إيصال',
                'amount' => '10.00',
                'expense_date' => now()->toDateString(),
                'attachment_path' => '../ai-chat/secret.pdf',
            ], $admin, 'exp-bad-path-1');
            $this->fail('Expected path traversal in attachment_path to be rejected.');
        } catch (BusinessException) {
        }

        // Legacy/bypassed model row outside expenses prefix must not be downloadable via ExpenseController::attachment.
        $tampered = Expense::create([
            'expense_number' => 'EXP-TAMPER-1',
            'branch_id' => $branch->id,
            'treasury_id' => $safe->id,
            'category' => 'أخرى',
            'amount' => '10.00',
            'expense_date' => now()->toDateString(),
            'attachment_path' => 'ai-chat/secret.pdf',
            'created_by' => $admin->id,
        ]);

        Auth::forgetGuards();
        $adminToken = $admin->createToken('t')->plainTextToken;
        $this->withToken($adminToken)
            ->get("/api/v1/admin/expenses/{$tampered->id}/attachment")
            ->assertNotFound();

        $valid = app(ExpenseService::class)->create([
            'branch_id' => $branch->id,
            'treasury_id' => $safe->id,
            'category' => 'صيانة',
            'amount' => '10.00',
            'expense_date' => now()->toDateString(),
            'attachment_path' => 'expense-receipts/99/receipt.pdf',
        ], $admin, 'exp-good-path-1');

        $this->withToken($adminToken)
            ->get("/api/v1/admin/expenses/{$valid->id}/attachment")
            ->assertOk();
    }

    public function test_f13_pos_drawer_requires_branch_and_creation_blocker_checks_central_and_active_branch(): void
    {
        $inactiveBranch = Branch::factory()->create(['is_active' => false]);
        $branchA = Branch::factory()->create(['is_active' => true]);
        $centralAdmin = $this->makeStaff(null, ['treasuries.create'], true);
        $branchUser = $this->makeStaff($branchA->id, ['treasuries.create'], false);
        $guards = app(FinanceWriteGuards::class);

        // Branch-restricted staff must be denied when attempting central (null branch) creation.
        $this->assertNotNull($guards->creationBlocker($branchUser, null, TreasuryType::MainSafe->value));
        // POS drawer cannot be central (branch_id = null).
        $this->assertNotNull($guards->creationBlocker($centralAdmin, null, TreasuryType::PosDrawer->value));
        // POS drawer cannot be created on inactive branch.
        $this->assertNotNull($guards->creationBlocker($centralAdmin, $inactiveBranch->id, TreasuryType::PosDrawer->value));

        try {
            app(TreasuryService::class)->createAccount([
                'name' => 'Central Drawer',
                'code' => 'CD-1',
                'branch_id' => null,
                'type' => TreasuryType::PosDrawer->value,
                'is_active' => true,
            ], $centralAdmin);
            $this->fail('Expected pos_drawer with null branch_id to throw BusinessException.');
        } catch (BusinessException) {
        }

        try {
            app(TreasuryService::class)->createAccount([
                'name' => 'Inactive Safe',
                'code' => 'IS-1',
                'branch_id' => $inactiveBranch->id,
                'type' => TreasuryType::MainSafe->value,
                'is_active' => true,
            ], $centralAdmin, '0.00');
            $this->fail('Expected zero-opening treasury on inactive branch to be rejected.');
        } catch (BusinessException|\Illuminate\Auth\Access\AuthorizationException) {
        }
    }

    public function test_f14_expense_and_transfer_controllers_accept_amounts_up_to_numeric_14_2(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $admin = $this->makeStaff($branch->id, ['expenses.create', 'treasury_transfers.create'], true);
        $safeA = $this->makeTreasury($branch, TreasuryType::MainSafe->value, '500000000.00', 'Big Safe A');
        $safeB = $this->makeTreasury($branch, TreasuryType::MainSafe->value, '0.00', 'Big Safe B');

        Auth::forgetGuards();
        $adminToken = $admin->createToken('t')->plainTextToken;
        $this->withToken($adminToken)
            ->withHeader('Idempotency-Key', 't8-f14-transfer-1')
            ->postJson('/api/v1/admin/treasury-transfers', [
                'from_treasury_id' => $safeA->id,
                'to_treasury_id' => $safeB->id,
                'amount' => '100000000.00',
            ])
            ->assertCreated();

        $this->withToken($adminToken)
            ->withHeader('Idempotency-Key', 't8-f14-expense-1')
            ->postJson('/api/v1/admin/expenses', [
                'branch_id' => $branch->id,
                'treasury_id' => $safeA->id,
                'category' => 'أصول',
                'amount' => '100000000.00',
                'expense_date' => now()->toDateString(),
            ])
            ->assertCreated();
    }
}
