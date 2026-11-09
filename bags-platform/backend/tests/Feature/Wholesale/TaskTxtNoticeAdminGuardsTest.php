<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Finance\TreasuryType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Treasury;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TaskTxtNoticeAdminGuardsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    private function staffToken(array $permissions, ?int $branchId = null): string
    {
        $role = Role::create(['name' => 'role-'.uniqid(), 'guard_name' => 'staff']);
        foreach ($permissions as $permission) {
            $role->givePermissionTo($permission);
        }
        $staff = User::factory()->create(['branch_id' => $branchId]);
        $staff->assignRole($role);

        return $staff->createToken('staff')->plainTextToken;
    }

    private function asStaff(string $token): static
    {
        Auth::forgetGuards();

        return $this->withToken($token);
    }

    private function pendingNotice(): WholesalePaymentNotice
    {
        $customer = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved', 'credit_limit' => 100000]);

        return WholesalePaymentNotice::factory()->create([
            'customer_id' => $customer->id,
            'amount' => 100,
            'status' => 'pending_review',
        ]);
    }

    public function test_index_and_show_require_view_permission(): void
    {
        $notice = $this->pendingNotice();
        $token = $this->staffToken([]);

        $this->asStaff($token)->getJson('/api/v1/admin/payment-notices')->assertForbidden();
        $this->asStaff($token)->getJson("/api/v1/admin/payment-notices/{$notice->id}")->assertForbidden();
    }

    public function test_approve_and_reject_require_their_own_permissions(): void
    {
        $notice = $this->pendingNotice();
        $token = $this->staffToken(['payment_notices.view']);

        $this->asStaff($token)->getJson('/api/v1/admin/payment-notices')->assertOk();
        $this->asStaff($token)->postJson("/api/v1/admin/payment-notices/{$notice->id}/approve", [])->assertForbidden();
        $this->asStaff($token)->postJson("/api/v1/admin/payment-notices/{$notice->id}/reject", [])->assertForbidden();

        $this->assertSame('pending_review', $notice->fresh()->status->value);
    }

    public function test_branch_restricted_staff_cannot_approve_on_foreign_branch_treasury(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $notice = $this->pendingNotice();
        $foreignTreasury = Treasury::create([
            'name' => 'خزينة فرع', 'code' => 'BR-'.uniqid(), 'type' => TreasuryType::BankAccount,
            'branch_id' => $branchB->id, 'current_balance' => 0, 'is_active' => true,
        ]);
        $token = $this->staffToken(['payment_notices.view', 'payment_notices.approve'], $branchA->id);

        $this->asStaff($token)->postJson("/api/v1/admin/payment-notices/{$notice->id}/approve", [
            'treasury_id' => $foreignTreasury->id,
        ])->assertForbidden();

        $this->assertSame('pending_review', $notice->fresh()->status->value);
        $this->assertDatabaseCount('treasury_transactions', 0);
    }

    public function test_branch_restricted_staff_index_is_currently_unscoped(): void
    {
        // Documents current behavior: index/show/reject carry no branch scope
        // while receipt download does. Owner decision on scoping is pending.
        $branchA = Branch::factory()->create();
        $notice = $this->pendingNotice();
        $token = $this->staffToken(['payment_notices.view'], $branchA->id);

        $this->asStaff($token)->getJson('/api/v1/admin/payment-notices')
            ->assertOk()
            ->assertJsonFragment(['id' => $notice->id]);
    }
}
