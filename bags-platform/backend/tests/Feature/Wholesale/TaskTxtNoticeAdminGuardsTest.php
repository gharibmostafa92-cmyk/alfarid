<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Finance\TreasuryType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
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
        $token = $this->staffToken(['payment_notices.view', 'inventory.all_branches']);

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

    public function test_branch_restricted_staff_cannot_approve_free_notice_through_service(): void
    {
        $branch = Branch::factory()->create();
        $notice = $this->pendingNotice();
        Order::factory()->create([
            'customer_id' => $notice->customer_id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'grand_total' => 100,
            'subtotal' => 100,
            'allocated_branch_id' => $branch->id,
        ]);
        $notice->update(['amount' => 50]);
        $treasury = Treasury::create([
            'name' => 'خزينة الفرع', 'code' => 'OWN-'.uniqid(), 'type' => TreasuryType::BankAccount,
            'branch_id' => $branch->id, 'current_balance' => 0, 'is_active' => true,
        ]);
        $staff = User::factory()->create(['branch_id' => $branch->id]);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(\App\Services\Wholesale\WholesalePaymentNoticeService::class)
            ->approve($notice, $staff->id, $treasury->id);
    }

    public function test_branch_staff_can_only_access_notices_for_their_branch(): void
    {
        $branch = Branch::factory()->create();
        $own = $this->pendingNotice();
        $own->update(['order_id' => Order::factory()->create([
            'customer_id' => $own->customer_id,
            'allocated_branch_id' => $branch->id,
        ])->id]);
        $central = $this->pendingNotice();
        $token = $this->staffToken([
            'payment_notices.view', 'payment_notices.approve', 'payment_notices.reject',
        ], $branch->id);

        $this->asStaff($token)->getJson('/api/v1/admin/payment-notices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id);
        $this->asStaff($token)->getJson("/api/v1/admin/payment-notices/{$own->id}")->assertOk();
        $this->asStaff($token)->getJson("/api/v1/admin/payment-notices/{$central->id}")->assertForbidden();
        $this->asStaff($token)->postJson("/api/v1/admin/payment-notices/{$central->id}/reject", [
            'admin_notes' => 'رفض الإشعار',
        ])->assertForbidden();
        $this->asStaff($token)->postJson("/api/v1/admin/payment-notices/{$own->id}/approve")->assertForbidden();
        $this->assertDatabaseCount('treasury_transactions', 0);
    }
}
