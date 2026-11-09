<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Enums\TreasuryType;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Ai\ToolRegistry;
use App\Services\Shared\SettingService;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\FinancialPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Phase 3: expense write tools (W8-W10) — create/update/void via ExpenseService. */
class FinanceExpenseToolsTest extends TestCase
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

    private function admin(): User
    {
        $u = $this->staff(['ai_agent.use', 'ai_agent.execute']);
        $u->assignRole(Role::findOrCreate(StaffRole::SuperAdmin->value, 'staff'));

        return $u;
    }

    private function token(User $u): string
    {
        Auth::forgetGuards();

        return $u->createToken('test')->plainTextToken;
    }

    private function conv(User $u): AiConversation
    {
        return AiConversation::create(['user_id' => $u->id, 'model' => 'gemini-test']);
    }

    private function propose(User $u, string $tool, array $args)
    {
        return app(ToolRegistry::class)->dispatch($u, $this->conv($u), ['name' => $tool, 'args' => $args]);
    }

    private function confirm(User $u, AiPendingAction $action, ?string $typed = null)
    {
        return $this->withToken($this->token($u))->postJson(
            "/api/v1/admin/ai/actions/{$action->id}/confirm",
            $typed === null ? [] : ['typed_confirmation' => $typed]
        );
    }

    private function treasury(Branch $branch, string $balance = '5000.00'): Treasury
    {
        return Treasury::create(['name' => 'خزينة '.Str::random(6), 'code' => 'SAFE-'.Str::uuid(),
            'branch_id' => $branch->id, 'type' => TreasuryType::MainSafe,
            'current_balance' => $balance, 'is_active' => true]);
    }

    private function undo(User $u, AiPendingAction $action)
    {
        return $this->withToken($this->token($u))->postJson("/api/v1/admin/ai/actions/{$action->id}/undo");
    }

    public function test_create_expense_confirms_and_undo_voids_with_reversal(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);
        $treasury = $this->treasury($branch);

        $outcome = $this->propose($admin, 'create_expense', [
            'branch_id' => $branch->id, 'treasury_id' => $treasury->id,
            'category' => 'كهرباء', 'amount' => 'ألفين',
        ]);
        $this->assertSame('proposal', $outcome->type);
        $action = AiPendingAction::findOrFail($outcome->data['pending_action_id']);
        $this->confirm($admin, $action)->assertOk()->assertJsonPath('data.ok', true);

        $expense = Expense::findOrFail($action->fresh()->result['expense_id']);
        $this->assertSame(2000.0, (float) $expense->amount);
        $this->assertSame(3000.0, (float) $treasury->fresh()->current_balance);

        $this->undo($admin, $action->fresh())->assertOk();
        $this->assertNotNull($expense->fresh()->voided_at);
        $this->assertSame('تراجع إجراء AI', $expense->fresh()->void_reason);
        $this->assertSame(5000.0, (float) $treasury->fresh()->current_balance);
    }

    public function test_create_expense_rejects_drawer_and_bad_amount(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);
        $drawer = Treasury::create(['name' => 'درج', 'code' => 'POS-'.Str::uuid(), 'branch_id' => $branch->id,
            'type' => TreasuryType::PosDrawer, 'current_balance' => '100.00', 'is_active' => true]);
        $safe = $this->treasury($branch);

        $drawerOutcome = $this->propose($admin, 'create_expense', [
            'branch_id' => $branch->id, 'treasury_id' => $drawer->id, 'category' => 'كهرباء', 'amount' => '50',
        ]);
        $this->assertSame('denied', $drawerOutcome->type);

        $badAmount = $this->propose($admin, 'create_expense', [
            'branch_id' => $branch->id, 'treasury_id' => $safe->id, 'category' => 'كهرباء', 'amount' => 'سالب 50',
        ]);
        $this->assertSame('denied', $badAmount->type);
    }

    public function test_expense_proposal_normalizes_category_and_flags_duplicate(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);
        $treasury = $this->treasury($branch);
        app(\App\Services\Pos\ExpenseService::class)->create([
            'branch_id' => $branch->id, 'treasury_id' => $treasury->id, 'category' => 'كهرباء',
            'amount' => '100.00', 'expense_date' => now()->toDateString(),
        ], $admin, 'existing-expense');

        $outcome = $this->propose($admin, 'create_expense', [
            'treasury_id' => $treasury->id, 'category' => 'فاتورة الكهربا', 'amount' => '100',
        ]);

        $this->assertSame('proposal', $outcome->type);
        $this->assertSame('كهرباء', $outcome->uiBlock['after']['category']);
        $this->assertContains('فيه مصروف مشابه بنفس المبلغ والخزينة خلال آخر ٤٨ ساعة.', $outcome->uiBlock['warnings']);
    }

    public function test_update_expense_and_undo_restores_previous_values(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);
        $treasury = $this->treasury($branch);
        $expense = app(\App\Services\Pos\ExpenseService::class)->create([
            'branch_id' => $branch->id, 'treasury_id' => $treasury->id, 'category' => 'كهرباء',
            'amount' => '100.00', 'expense_date' => now()->toDateString(),
        ], $admin, 'test-'.Str::uuid()->toString());

        $outcome = $this->propose($admin, 'update_expense', ['expense_id' => $expense->id, 'category' => 'مياه']);
        $action = AiPendingAction::findOrFail($outcome->data['pending_action_id']);
        $this->confirm($admin, $action)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame('مياه', $expense->fresh()->category);

        $this->undo($admin, $action->fresh())->assertOk();
        $this->assertSame('كهرباء', $expense->fresh()->category);
    }

    public function test_update_voided_expense_denied_and_shift_tied_void_denied(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);
        $treasury = $this->treasury($branch);
        $svc = app(\App\Services\Pos\ExpenseService::class);
        $expense = $svc->create([
            'branch_id' => $branch->id, 'treasury_id' => $treasury->id, 'category' => 'كهرباء',
            'amount' => '100.00', 'expense_date' => now()->toDateString(),
        ], $admin, 'test-'.Str::uuid()->toString());
        $svc->void($expense, 'خطأ', $admin);

        $updateVoided = $this->propose($admin, 'update_expense', ['expense_id' => $expense->id, 'category' => 'مياه']);
        $this->assertSame('denied', $updateVoided->type);

        $shiftId = DB::table('pos_shifts')->insertGetId(['shift_number' => 'SHIFT-'.Str::uuid(),
            'user_id' => $admin->id, 'branch_id' => $branch->id, 'treasury_id' => $treasury->id,
            'status' => 'open', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $shiftExpense = Expense::create(['expense_number' => 'EXP-'.Str::uuid(), 'branch_id' => $branch->id,
            'treasury_id' => $treasury->id, 'shift_id' => $shiftId, 'category' => 'فرق',
            'amount' => '10.00', 'expense_date' => now()->toDateString(), 'created_by' => $admin->id]);
        $voidShift = $this->propose($admin, 'void_expense', ['expense_id' => $shiftExpense->id, 'reason' => 'x']);
        $this->assertSame('denied', $voidShift->type);
    }

    public function test_void_expense_reverses_balance(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);
        $treasury = $this->treasury($branch);
        $expense = app(\App\Services\Pos\ExpenseService::class)->create([
            'branch_id' => $branch->id, 'treasury_id' => $treasury->id, 'category' => 'كهرباء',
            'amount' => '300.00', 'expense_date' => now()->toDateString(),
        ], $admin, 'test-'.Str::uuid()->toString());
        $this->assertSame(4700.0, (float) $treasury->fresh()->current_balance);

        $noReason = $this->propose($admin, 'void_expense', ['expense_id' => $expense->id]);
        $this->assertSame('denied', $noReason->type);

        $outcome = $this->propose($admin, 'void_expense', ['expense_id' => $expense->id, 'reason' => 'فاتورة مكررة']);
        $action = AiPendingAction::findOrFail($outcome->data['pending_action_id']);
        $this->confirm($admin, $action)->assertStatus(422);
        $this->confirm($admin, $action, $expense->expense_number)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame(5000.0, (float) $treasury->fresh()->current_balance);
        $this->assertSame('فاتورة مكررة', $expense->fresh()->void_reason);
    }
}
