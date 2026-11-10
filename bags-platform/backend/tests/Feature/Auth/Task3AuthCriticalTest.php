<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\StaffRole;
use App\Models\Branch;
use App\Models\User;
use App\Services\Auth\LoginDestinationResolver;
use App\Services\Auth\StaffTokenService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

// task3 slice A (auth critical): C1 super-admin misrouted to POS,
// H1 pos-token lockout during must_change_password, H2 TTL mechanism
// (default stays 30d per committed contract; 24h needs product decision).
class Task3AuthCriticalTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_super_admin_with_pos_access_is_routed_to_admin_dashboard(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'branch_id' => null]);
        $role = Role::firstOrCreate(['name' => StaffRole::SuperAdmin->value, 'guard_name' => 'staff']);
        $role->givePermissionTo(Permission::findOrCreate('pos.access', 'staff'));
        $admin->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $result = app(LoginDestinationResolver::class)->resolve($admin);

        $this->assertSame('admin', $result['destination']);
        $this->assertSame('/dashboard', $result['path']);
    }

    public function test_single_custom_role_with_extra_admin_permission_is_not_pos_only(): void
    {
        $manager = User::factory()->create(['is_active' => true, 'branch_id' => null]);
        $role = Role::firstOrCreate(['name' => 'branch-manager', 'guard_name' => 'staff']);
        $role->givePermissionTo([
            Permission::findOrCreate('pos.access', 'staff'),
            Permission::findOrCreate('orders.view', 'staff'),
        ]);
        $manager->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($manager->fresh()->can('pos.access'));

        $result = app(LoginDestinationResolver::class)->resolve($manager);

        $this->assertSame('admin', $result['destination']);
    }

    public function test_single_cashier_role_with_pos_access_is_routed_to_pos(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $cashier = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $role = Role::firstOrCreate(['name' => StaffRole::Cashier->value, 'guard_name' => 'staff']);
        $role->givePermissionTo(Permission::findOrCreate('pos.access', 'staff'));
        $cashier->assignRole($role);

        $result = app(LoginDestinationResolver::class)->resolve($cashier);

        $this->assertSame('pos', $result['destination']);
    }

    public function test_pos_token_can_rotate_temporary_password(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $cashier = User::factory()->create([
            'is_active' => true,
            'branch_id' => $branch->id,
            'must_change_password' => true,
        ]);
        $cashier->assignRole(Role::firstOrCreate(['name' => StaffRole::Cashier->value, 'guard_name' => 'staff']));
        $token = $cashier->createToken('pos-token', ['pos'])->plainTextToken;

        Auth::forgetGuards();
        $this->getJson('/api/v1/admin/me', ['Authorization' => 'Bearer '.$token])->assertOk();

        Auth::forgetGuards();
        $this->putJson('/api/v1/admin/me/password', [
            'expected_version' => (int) $cashier->lock_version,
            'current_password' => 'password',
            'password' => 'new-secret-123',
        ], ['Authorization' => 'Bearer '.$token])->assertOk();

        $this->assertFalse($cashier->refresh()->must_change_password);
        $this->assertTrue(Hash::check('new-secret-123', $cashier->password));
    }

    public function test_temporary_password_cannot_be_reused_as_the_new_password(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'is_active' => true, 'branch_id' => $branch->id,
            'password' => 'temporary12345', 'must_change_password' => true,
        ]);
        $user->assignRole(Role::firstOrCreate(['name' => StaffRole::Cashier->value, 'guard_name' => 'staff']));
        $token = $user->createToken('staff-token')->plainTextToken;

        $this->putJson('/api/v1/admin/me/password', [
            'expected_version' => (int) $user->lock_version,
            'current_password' => 'temporary12345',
            'password' => 'temporary12345',
        ], ['Authorization' => 'Bearer '.$token])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertTrue($user->refresh()->must_change_password);
    }

    public function test_staff_token_ttl_follows_explicit_configuration(): void
    {
        // Lifetime is config-driven; the default stays at the committed 30d
        // until the H2 product decision lands (see docs/TASK3_DEPLOYMENT_STATUS.md).
        config(['auth.staff_token_minutes' => 1440]);
        $user = User::factory()->create(['is_active' => true]);
        app(StaffTokenService::class)->issue($user);

        $this->assertEqualsWithDelta(1440, (int) now()->diffInMinutes($user->tokens()->latest('id')->first()->expires_at), 1);
    }
}
