<?php

declare(strict_types=1);

namespace Tests\Feature\Staff;

use App\Enums\StaffRole;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use App\Services\Auth\StaffTokenService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

// task3 HIGH-06: token ability narrowing, self-edit session survival,
// and customer financial-field optimistic concurrency.
class Task3StaffSessionLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function admin(array $permissions): array
    {
        foreach ($permissions as $perm) {
            Permission::findOrCreate($perm, 'staff');
        }
        $user = User::factory()->create(['branch_id' => null]);
        $user->givePermissionTo(array_merge($permissions, ['inventory.all_branches']));
        $user->assignRole(Role::findOrCreate('task3-admin-role', 'staff'));
        Role::findByName('task3-admin-role', 'staff')->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [$user, $user->createToken('admin-token', ['*'])->plainTextToken];
    }

    public function test_issue_honors_a_narrower_requested_ability_instead_of_widening(): void
    {
        [$admin] = $this->admin(['users.view']);

        $token = app(StaffTokenService::class)->issue($admin, null, ['pos']);
        $abilities = $admin->tokens()->latest('id')->firstOrFail()->abilities;

        $this->assertSame(['pos'], $abilities);
        $this->assertNotEmpty($token);
    }

    public function test_issue_rejects_requested_abilities_outside_the_resolved_grant(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $cashier = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $role = Role::findOrCreate(StaffRole::Cashier->value, 'staff');
        $role->givePermissionTo(Permission::findOrCreate('pos.access', 'staff'));
        $cashier->assignRole($role);

        try {
            app(StaffTokenService::class)->issue($cashier, null, ['users.view']);
            $this->fail('An ability outside the resolved grant must not be issued.');
        } catch (\App\Exceptions\BusinessException $exception) {
            $this->assertSame(403, $exception->status);
            $this->assertSame('invalid_token_abilities', $exception->errorCode);
        }

        $this->assertSame(0, $cashier->tokens()->count());
    }

    public function test_issue_defaults_to_the_resolved_grant_when_no_ability_is_requested(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $cashier = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $cashier->assignRole(Role::firstOrCreate(['name' => StaffRole::Cashier->value, 'guard_name' => 'staff']));
        Role::findByName(StaffRole::Cashier->value, 'staff')
            ->givePermissionTo(Permission::findOrCreate('pos.access', 'staff'));

        app(StaffTokenService::class)->issue($cashier);
        $abilities = $cashier->tokens()->latest('id')->firstOrFail()->abilities;

        $this->assertSame(['pos'], $abilities);
    }

    public function test_self_profile_edit_keeps_the_current_session_and_revokes_others(): void
    {
        [$admin, $token] = $this->admin(['users.view', 'users.update', 'users.manage']);
        $admin->createToken('stale-session', ['*']);
        $this->assertSame(2, $admin->tokens()->count());

        Auth::forgetGuards();
        $this->withToken($token)->putJson('/api/v1/admin/users/'.$admin->id, [
            'expected_version' => $admin->refresh()->lock_version,
            'email' => 'rotated-self@example.test',
            'current_password' => 'password',
        ])->assertOk();

        // Current session survives with the other session revoked.
        $this->assertSame(1, $admin->tokens()->count());
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/admin/me')->assertOk();
    }

    public function test_customer_credit_limit_change_with_a_stale_timestamp_is_rejected(): void
    {
        [$admin, $token] = $this->admin(['customers.view', 'customers.update', 'customers.approve']);
        $customer = Customer::factory()->create(['credit_limit' => '1000.00']);
        $stale = $customer->updated_at->copy()->subHour()->toDateTimeString();

        Auth::forgetGuards();
        $this->withToken($token)->putJson('/api/v1/admin/customers/'.$customer->id, [
            'credit_limit' => '5000.00',
            'expected_updated_at' => $stale,
        ])->assertStatus(409);

        $this->assertSame('1000.00', (string) $customer->refresh()->credit_limit);
    }

    public function test_customer_credit_limit_change_with_a_fresh_timestamp_is_accepted(): void
    {
        [$admin, $token] = $this->admin(['customers.view', 'customers.update', 'customers.approve']);
        $customer = Customer::factory()->create(['credit_limit' => '1000.00']);

        Auth::forgetGuards();
        $this->withToken($token)->putJson('/api/v1/admin/customers/'.$customer->id, [
            'credit_limit' => '5000.00',
            'expected_updated_at' => $customer->updated_at->toDateTimeString(),
        ])->assertOk();

        $this->assertSame('5000.00', (string) $customer->refresh()->credit_limit);
    }
}
