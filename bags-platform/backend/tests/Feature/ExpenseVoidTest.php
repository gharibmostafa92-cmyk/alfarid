<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Enums\TreasuryType;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Models\User;
use App\Services\ReportService;
use Database\Seeders\FinancialPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * C1: حذف المصروف صار إلغاءً (void) يعكس الحركة بدل الحذف الفعلي،
 * مع مفتاح عدم تكرار إلزامي على الإنشاء.
 */
class ExpenseVoidTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([SettingSeeder::class, FinancialPermissionSeeder::class]);
        $this->staff = User::factory()->create();
        $role = Role::findOrCreate(StaffRole::SuperAdmin->value, 'staff');
        $this->staff->assignRole($role);
        $this->token = $this->staff->createToken('audit')->plainTextToken;
    }

    private function treasury(array $attributes = []): Treasury
    {
        return Treasury::create([
            'name' => 'Main Safe', 'code' => 'SAFE-'.Str::uuid(),
            'branch_id' => Branch::factory()->create(['is_active' => true])->id,
            'type' => TreasuryType::MainSafe, 'current_balance' => '1000.00', 'is_active' => true,
            ...$attributes,
        ]);
    }

    private function payload(Treasury $treasury, array $attributes = []): array
    {
        return [
            'branch_id' => $treasury->branch_id, 'treasury_id' => $treasury->id,
            'category' => 'صيانة', 'amount' => 100, 'expense_date' => now()->toDateString(),
            ...$attributes,
        ];
    }

    private function createExpense(Treasury $treasury): Expense
    {
        $id = $this->withToken($this->token)->postJson('/api/v1/admin/expenses', $this->payload($treasury), [
            'Idempotency-Key' => 'expense-'.Str::uuid(),
        ])->assertCreated()->json('data.id');

        return Expense::findOrFail($id);
    }

    private function authenticateBranchStaff(int $branchId, array $permissions): void
    {
        $staff = User::factory()->create(['branch_id' => $branchId]);
        foreach ($permissions as $permission) {
            $staff->givePermissionTo(Permission::findOrCreate($permission, 'staff'));
        }
        Auth::forgetGuards();
        $this->withToken($staff->createToken('branch-audit')->plainTextToken);
    }

    public function test_destroy_voids_expense_and_reverses_treasury_balance(): void
    {
        $treasury = $this->treasury();
        $payload = $this->payload($treasury);

        $create = $this->withToken($this->token)->postJson(
            '/api/v1/admin/expenses',
            $payload,
            ['Idempotency-Key' => 'exp-key-001']
        );
        $create->assertCreated();
        $expenseId = (int) $create->json('data.id');
        $this->assertSame('900.00', number_format((float) $treasury->fresh()->current_balance, 2, '.', ''));

        // نفس المفتاح + نفس الحمولة يعيد نفس السجل بلا خصم ثانٍ
        $retry = $this->withToken($this->token)->postJson(
            '/api/v1/admin/expenses',
            $payload,
            ['Idempotency-Key' => 'exp-key-001']
        );
        $retry->assertCreated();
        $this->assertSame($expenseId, (int) $retry->json('data.id'));
        $this->assertSame('900.00', number_format((float) $treasury->fresh()->current_balance, 2, '.', ''));

        $this->withToken($this->token)->postJson('/api/v1/admin/expenses', [...$payload, 'amount' => 200], [
            'Idempotency-Key' => 'exp-key-001',
        ])->assertConflict();
        $this->assertDatabaseCount('expenses', 1);
        $this->assertSame('900.00', $treasury->fresh()->current_balance);

        $destroy = $this->withToken($this->token)->postJson("/api/v1/admin/expenses/{$expenseId}/void", [
            'void_reason' => 'تم تسجيل المصروف بالخطأ',
        ]);
        $destroy->assertOk();

        $expense = Expense::findOrFail($expenseId);
        $this->assertNotNull($expense->voided_at);
        $this->assertSame('تم تسجيل المصروف بالخطأ', $expense->void_reason);
        $this->assertSame($this->staff->id, $expense->voided_by);
        $this->assertSame('1000.00', number_format((float) $treasury->fresh()->current_balance, 2, '.', ''));
        $this->assertSame(2, TreasuryTransaction::where('reference_type', Expense::class)->where('reference_id', $expenseId)->count());
        $report = app(ReportService::class)->profitReport(now()->toDateString(), now()->toDateString(), $treasury->branch_id);
        $this->assertSame(0.0, $report['total_expenses']);

        // إلغاء مزدوج مرفوض
        $again = $this->withToken($this->token)->deleteJson("/api/v1/admin/expenses/{$expenseId}");
        $again->assertStatus(422);
    }

    public function test_store_requires_idempotency_key(): void
    {
        $treasury = $this->treasury();
        $this->withToken($this->token)->postJson('/api/v1/admin/expenses', $this->payload($treasury))->assertStatus(422);
    }

    public function test_inactive_treasury_allows_an_audited_expense_reversal_only(): void
    {
        $treasury = $this->treasury();
        $expense = $this->createExpense($treasury);
        $treasury->update(['is_active' => false]);
        $this->withToken($this->token)->deleteJson("/api/v1/admin/expenses/{$expense->id}")->assertUnprocessable();
        $this->assertNull($expense->fresh()->voided_at);
        $this->withToken($this->token)->deleteJson("/api/v1/admin/expenses/{$expense->id}", [
            'void_reason' => 'تصحيح قيد قديم',
        ])->assertOk();
        $this->assertSame('1000.00', $treasury->fresh()->current_balance);
        $this->assertDatabaseHas('treasury_transactions', [
            'reference_id' => $expense->id, 'type' => 'expense_reversal', 'amount' => '100.00',
        ]);
    }

    public static function foreignBranchActions(): array
    {
        return [['create'], ['read'], ['update'], ['void']];
    }

    #[DataProvider('foreignBranchActions')]
    public function test_branch_staff_cannot_access_another_branches_expenses(string $action): void
    {
        $treasury = $this->treasury();
        $expense = $this->createExpense($treasury);
        $this->authenticateBranchStaff(Branch::factory()->create()->id, [
            'expenses.view', 'expenses.create', 'expenses.update', 'expenses.delete',
        ]);
        $this->getJson('/api/v1/admin/expenses')->assertOk()->assertJsonCount(0, 'data');

        $response = match ($action) {
            'create' => $this->postJson('/api/v1/admin/expenses', $this->payload($treasury), ['Idempotency-Key' => 'foreign-expense']),
            'read' => $this->getJson("/api/v1/admin/expenses/{$expense->id}"),
            'update' => $this->putJson("/api/v1/admin/expenses/{$expense->id}", ['notes' => 'Unauthorized edit']),
            'void' => $this->postJson("/api/v1/admin/expenses/{$expense->id}/void", ['void_reason' => 'Unauthorized void']),
        };
        $response->assertForbidden();
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'notes' => null, 'voided_at' => null]);
        $this->assertSame('900.00', $treasury->fresh()->current_balance);
        $this->assertDatabaseCount('treasury_transactions', 1);
    }

    public static function invalidAdministrativeExpenses(): array
    {
        return [['inactive'], ['mismatched_branch'], ['pos_drawer'], ['excess_decimal_places']];
    }

    #[DataProvider('invalidAdministrativeExpenses')]
    public function test_admin_expenses_reject_invalid_treasuries_and_amount_precision(string $case): void
    {
        $treasury = $this->treasury(match ($case) {
            'inactive' => ['is_active' => false],
            'pos_drawer' => ['type' => TreasuryType::PosDrawer],
            default => [],
        });
        $payload = $this->payload($treasury, match ($case) {
            'mismatched_branch' => ['branch_id' => Branch::factory()->create()->id],
            'excess_decimal_places' => ['amount' => '0.015'],
            default => [],
        });

        $response = $this->withToken($this->token)->postJson('/api/v1/admin/expenses', $payload, [
            'Idempotency-Key' => 'invalid-expense',
        ])->assertUnprocessable();
        if ($case === 'excess_decimal_places') {
            $response->assertJsonValidationErrors('amount');
        }
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('treasury_transactions', 0);
        $this->assertSame('1000.00', $treasury->fresh()->current_balance);
    }

    public function test_post_void_requires_expense_delete_permission(): void
    {
        $treasury = $this->treasury();
        $expense = $this->createExpense($treasury);
        $this->authenticateBranchStaff($treasury->branch_id, ['expenses.view']);

        $this->postJson("/api/v1/admin/expenses/{$expense->id}/void", ['void_reason' => 'No delete permission'])
            ->assertForbidden();
        $this->assertNull($expense->fresh()->voided_at);
        $this->assertSame('900.00', $treasury->fresh()->current_balance);
    }

    public function test_delete_requires_void_reason(): void
    {
        // M2: مسار DELETE يشترط السبب كمسار void — بلا سبب يُرفض بـ 422
        // ولا يُعكس أي قيد في الخزينة.
        $treasury = $this->treasury();
        $expense = $this->createExpense($treasury);

        $this->withToken($this->token)->deleteJson("/api/v1/admin/expenses/{$expense->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('void_reason');
        $this->withToken($this->token)->deleteJson("/api/v1/admin/expenses/{$expense->id}", [])
            ->assertUnprocessable()->assertJsonValidationErrors('void_reason');

        $this->assertNull($expense->fresh()->voided_at);
        $this->assertSame('900.00', number_format((float) $treasury->fresh()->current_balance, 2, '.', ''));
        $this->assertSame(1, TreasuryTransaction::where('reference_type', Expense::class)->where('reference_id', $expense->id)->count());
    }

    public function test_void_reversal_uses_expense_reversal_type(): void
    {
        // L6: قيد العكس بنوع مستقل حتى لا يُقرأ "مصروفًا موجبًا" في
        // أي تجميع حسب النوع.
        $treasury = $this->treasury();
        $expense = $this->createExpense($treasury);

        $this->withToken($this->token)->postJson("/api/v1/admin/expenses/{$expense->id}/void", [
            'void_reason' => 'قيد خاطئ',
        ])->assertOk();

        $this->assertDatabaseHas('treasury_transactions', [
            'treasury_id' => $treasury->id,
            'type' => 'expense_reversal',
            'amount' => 100,
            'reference_type' => Expense::class,
            'reference_id' => $expense->id,
        ]);
        $this->assertDatabaseMissing('treasury_transactions', [
            'reference_type' => Expense::class,
            'reference_id' => $expense->id,
            'type' => 'expense',
            'amount' => 100,
        ]);
    }

    public function test_post_void_requires_a_reason_even_for_an_allowed_admin(): void
    {
        $treasury = $this->treasury();
        $expense = $this->createExpense($treasury);

        $this->postJson("/api/v1/admin/expenses/{$expense->id}/void", [])
            ->assertUnprocessable()->assertJsonValidationErrors('void_reason');
        $this->postJson("/api/v1/admin/expenses/{$expense->id}/void", ['void_reason' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors('void_reason');
        $this->assertNull($expense->fresh()->voided_at);
        $this->assertSame('900.00', $treasury->fresh()->current_balance);
        $this->assertDatabaseCount('treasury_transactions', 1);
    }
}
