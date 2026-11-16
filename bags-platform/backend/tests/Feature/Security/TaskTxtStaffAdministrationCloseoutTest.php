<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TaskTxtStaffAdministrationCloseoutTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_role_permission_reduction_revokes_existing_staff_tokens(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $permission = Permission::findOrCreate('finance.reports.view', 'staff');
        $role = Role::query()->create(['name' => 'report-viewer', 'guard_name' => 'staff']);
        $role->syncPermissions([$permission]);
        $role->refresh();
        $staff = User::factory()->create();
        $staff->assignRole($role);
        $staff->createToken('before-permission-reduction', ['*']);

        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $adminToken = $admin->createToken('admin', ['*'])->plainTextToken;

        $this->withToken($adminToken)->putJson('/api/v1/admin/roles/'.$role->id, [
            'expected_version' => $role->lock_version,
            'permissions' => [],
        ])->assertOk();

        $this->assertSame(0, $staff->tokens()->count());
    }

    public function test_self_password_rotation_skips_the_global_staff_advisory_lock(): void
    {
        $staff = User::factory()->create([
            'password' => 'CurrentPass123',
            'must_change_password' => true,
        ]);
        foreach (['branches.view', 'inventory.all_branches', 'users.view'] as $name) {
            $staff->givePermissionTo(Permission::findOrCreate($name, 'staff'));
        }
        $token = $staff->createToken('self-rotation', ['*'])->plainTextToken;
        $globalLockQueries = [];
        DB::listen(static function ($query) use (&$globalLockQueries): void {
            if (str_contains($query->sql, 'pg_advisory_xact_lock(79301, 1)')) {
                $globalLockQueries[] = $query->sql;
            }
        });

        Auth::forgetGuards();
        $this->withToken($token)->putJson('/api/v1/admin/me/password', [
            'expected_version' => $staff->fresh()->lock_version,
            'current_password' => 'CurrentPass123',
            'password' => 'NewValidPass123',
        ])->assertOk();

        $this->assertSame([], $globalLockQueries);
    }

    public function test_denied_financial_and_inventory_mutations_are_audited(): void
    {
        $staff = User::factory()->create(['is_active' => true]);
        $token = $staff->createToken('limited-staff', ['*'])->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/admin/treasuries', [])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/admin/stocktakes', [])->assertForbidden();

        $this->assertDatabaseHas('staff_audit_logs', [
            'actor_id' => $staff->id,
            'action' => 'administration.denied',
            'target_type' => 'treasury',
        ]);
        $this->assertDatabaseHas('staff_audit_logs', [
            'actor_id' => $staff->id,
            'action' => 'administration.denied',
            'target_type' => 'stocktake',
        ]);
    }

    public function test_staff_mutation_throttle_covers_financial_resources(): void
    {
        config(['auth.staff_admin_mutations_minute' => 1]);
        $staff = User::factory()->create(['is_active' => true]);
        $token = $staff->createToken('limited-staff', ['*'])->plainTextToken;
        RateLimiter::clear('staff:admin-mutations:'.$staff->id);

        $this->withToken($token)->postJson('/api/v1/admin/treasuries', [])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/admin/expenses', [])->assertStatus(429);
    }
}
