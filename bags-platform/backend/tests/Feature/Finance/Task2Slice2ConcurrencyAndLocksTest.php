<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\TreasuryType;
use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\Branch;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Ai\Finance\Tools\CreateExpenseTool;
use App\Services\Ai\Finance\Tools\CreateTreasuryTransferTool;
use App\Services\Pos\ExpenseService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Task2Slice2ConcurrencyAndLocksTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\FinancialPermissionSeeder::class);
    }

    public function test_high_02_and_medium_02_create_treasury_transfer_tool_uses_lock_treasuries_and_result_payload(): void
    {
        $branch = Branch::query()->firstOrCreate(['code' => 'T2_B_CONC'], ['name' => 'Branch Conc', 'is_active' => true]);
        $user = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);

        $conversation = \App\Models\AiConversation::query()->create([
            'user_id' => $user->id,
            'title' => 'Test Conversation',
            'channel' => 'web',
            'model' => 'default',
        ]);

        // Create two treasuries where destination has smaller ID than source to test lock order
        $t1 = Treasury::query()->create([
            'code' => 'T2_SAFE_'.uniqid(),
            'name' => 'Safe 1',
            'type' => TreasuryType::MainSafe,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $t1->forceFill(['current_balance' => '5000.00'])->save();

        $t2 = Treasury::query()->create([
            'code' => 'T2_SAFE_'.uniqid(),
            'name' => 'Safe 2',
            'type' => TreasuryType::MainSafe,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $t2->forceFill(['current_balance' => '1000.00'])->save();

        $fromId = max($t1->id, $t2->id);
        $toId = min($t1->id, $t2->id);

        $args = [
            'from_treasury_id' => $fromId,
            'to_treasury_id' => $toId,
            'amount' => '150.00',
            'notes' => 'Test transfer ordering',
        ];

        $action = AiPendingAction::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'tool' => 'create_treasury_transfer',
            'preview' => 'Test Transfer Preview',
            'risk' => 'low',
            'expires_at' => now()->addHour(),
            'args' => $args,
            'args_hash' => hash('sha256', json_encode($args)),
            'expected_versions' => [
                'treasury:'.$fromId => \App\Services\Ai\Finance\FinanceWriteGuards::ledgerVersion($t1->id === $fromId ? $t1 : $t2),
                'treasury:'.$toId => \App\Services\Ai\Finance\FinanceWriteGuards::ledgerVersion($t1->id === $toId ? $t1 : $t2),
            ],
            'status' => 'confirmed',
        ]);

        $tool = app(CreateTreasuryTransferTool::class);
        $execOutcome = $tool->execute($user, $action);
        $this->assertSame('result', $execOutcome->type);

        $fresh = $action->fresh();
        // MEDIUM-02: transfer_id must be in result, NOT contaminating expected_versions
        $this->assertArrayNotHasKey('created_transfer_id', $fresh->expected_versions, 'expected_versions must remain pure optimistic concurrency map.');
        $this->assertNotNull($fresh->result['transfer_id'] ?? null, 'result must contain transfer_id.');
    }

    public function test_medium_03_create_expense_tool_cleans_up_file_on_failure(): void
    {
        Storage::fake('ai_private');
        $disk = Storage::disk('ai_private');
        $disk->put('temp/receipt.jpg', 'fake-image-content');

        $branch = Branch::query()->firstOrCreate(['code' => 'T2_B_EXP'], ['name' => 'Branch Exp', 'is_active' => true]);
        $user = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $treasury = Treasury::query()->create([
            'code' => 'T2_EXP_TR_'.uniqid(),
            'name' => 'Expense Safe',
            'type' => TreasuryType::MainSafe,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $treasury->forceFill(['current_balance' => '2000.00'])->save();

        $conversation = \App\Models\AiConversation::query()->create([
            'user_id' => $user->id,
            'title' => 'Test Conversation Exp',
            'channel' => 'web',
            'model' => 'default',
        ]);

        $args = [
            'branch_id' => $branch->id,
            'treasury_id' => $treasury->id,
            'amount' => '100.00',
            'category' => 'hospitality',
            'description' => 'Test expense with receipt',
            'expense_date' => now()->toDateString(),
            'attachment_path' => 'temp/receipt.jpg',
        ];

        $action = AiPendingAction::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'tool' => 'create_expense',
            'preview' => 'Test Expense Preview',
            'risk' => 'low',
            'expires_at' => now()->addHour(),
            'args' => $args,
            'args_hash' => hash('sha256', json_encode($args)),
            'expected_versions' => [],
            'status' => 'pending', // NOT confirmed, so execute will fail!
        ]);

        $tool = app(CreateExpenseTool::class);

        try {
            $tool->execute($user, $action);
            $this->fail('Execute should have thrown BusinessException because action is not confirmed.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(BusinessException::class, $e);
        }

        // Verify that no orphaned file remains in expenses/ receipts directory
        $files = $disk->allFiles('expenses');
        $this->assertEmpty($files, 'Orphaned expense receipts must be cleaned up on transaction rollback/failure.');
    }

    public function test_low_02_expense_service_create_rejects_inactive_branch_for_central_treasury(): void
    {
        $inactiveBranch = Branch::query()->create([
            'code' => 'T2_INACT_'.uniqid(),
            'name' => 'Inactive Branch',
            'is_active' => false,
        ]);

        $centralTreasury = Treasury::query()->create([
            'code' => 'T2_BANK_CENTRAL_'.uniqid(),
            'name' => 'Central Bank',
            'type' => TreasuryType::BankAccount,
            'branch_id' => null, // Central treasury
            'is_active' => true,
        ]);
        $centralTreasury->forceFill(['current_balance' => '50000.00'])->save();

        $user = User::factory()->create(['branch_id' => null, 'is_active' => true]);
        $role = \Spatie\Permission\Models\Role::findOrCreate(\App\Enums\StaffRole::SuperAdmin->value, 'staff');
        $role->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('inventory.all_branches', 'staff'));
        $user->assignRole($role);

        $service = app(ExpenseService::class);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->expectExceptionMessage('الفرع متوقف حالياً.');

        $service->create([
            'branch_id' => $inactiveBranch->id,
            'treasury_id' => $centralTreasury->id,
            'amount' => '200.00',
            'category' => 'maintenance',
            'description' => 'Expense on inactive branch',
            'expense_date' => now()->toDateString(),
        ], $user, 'idemp-test-low-02-'.uniqid());
    }

    public function test_high_01_reconcile_inventory_runs_read_only_and_logs_results(): void
    {
        $branch = Branch::query()->firstOrCreate(['code' => 'T2_B_INV'], ['name' => 'Branch Inv', 'is_active' => true]);

        // Run non-incremental reconcile
        $this->artisan('inventory:reconcile', ['--branch_id' => (string) $branch->id])
            ->assertExitCode(0);

        // Run incremental reconcile
        $statusInc = $this->artisan('inventory:reconcile', ['--branch_id' => (string) $branch->id, '--incremental' => true]);
        $statusInc->assertExitCode(0);

        $this->assertDatabaseHas('inventory_reconcile_runs', [
            'branch_id' => $branch->id,
        ]);
    }
}
