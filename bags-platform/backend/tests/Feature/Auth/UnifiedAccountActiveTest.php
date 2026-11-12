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

/** L4: unified me/logout sit behind an active-account check, not bare sanctum auth. */
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
        $this->withToken($token)->postJson('/api/v1/auth/logout')
            ->assertForbidden()->assertJsonPath('message', 'تم إيقاف الحساب.');
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
