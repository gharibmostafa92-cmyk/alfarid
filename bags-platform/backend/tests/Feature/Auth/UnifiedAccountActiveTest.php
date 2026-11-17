<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\Customer\CustomerStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** L4: unified me sits behind an active-account check; logout (session revocation) stays reachable for any valid token. */
class UnifiedAccountActiveTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_disabled_staff_cannot_use_unified_me(): void
    {
        $staff = User::factory()->create(['is_active' => false]);
        $token = $staff->createToken('staff')->plainTextToken;

        // The middleware rejects before the controller, with its own message.
        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertForbidden()->assertJsonPath('message', 'تم إيقاف الحساب.');
    }

    public function test_disabled_staff_can_still_revoke_session_via_unified_logout(): void
    {
        $staff = User::factory()->create(['is_active' => false]);
        $token = $staff->createToken('staff')->plainTextToken;

        // Session revocation must always succeed for a valid token so a
        // deactivated identity can still sign itself out everywhere.
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertSame(0, $staff->tokens()->count());
    }

    public function test_suspended_customer_cannot_use_unified_me(): void
    {
        $customer = Customer::factory()->create(['status' => CustomerStatus::Suspended]);
        $token = $customer->createToken('customer')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertForbidden()->assertJsonPath('message', 'تم إيقاف الحساب.');
    }

    public function test_active_accounts_keep_access(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $staff = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $staff->assignRole(Role::findOrCreate('cashier', 'staff'));
        $staff->givePermissionTo(Permission::findOrCreate('pos.access', 'staff'));
        $customer = Customer::factory()->create(['status' => CustomerStatus::Approved]);

        $this->withToken($staff->createToken('staff')->plainTextToken)->getJson('/api/v1/auth/me')->assertOk();
        $this->withToken($customer->createToken('customer')->plainTextToken)->getJson('/api/v1/auth/me')->assertOk();
    }
}
