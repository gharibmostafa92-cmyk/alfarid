<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Actions\Branches\UpdateBranch;
use App\Actions\Staff\CreateRole;
use App\Actions\Staff\DeactivateStaffUser;
use App\Actions\Staff\UpdateRole;
use App\Actions\Staff\UpdateStaffUser;
use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Enums\StaffRole;
use App\Http\Middleware\AuditStaffAdministration;
use App\Http\Middleware\EnsureWholesaleApproved;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use App\Services\Auth\StaffAdministration;
use App\Services\Auth\StaffHandoffService;
use App\Services\Inventory\BranchAccess;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class Task3RoadmapSlice2Test extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_critical_02_unknown_permission_returns_false_instead_of_500(): void
    {
        Permission::findOrCreate('users.view', 'staff');
        $actor = User::factory()->create();
        $actor->givePermissionTo('users.view');

        $this->assertFalse(StaffAdministration::mayGrantPermissions($actor, ['nonexistent.permission']));

        $role = Role::create(['name' => 'Custom Role', 'guard_name' => 'staff']);
        $target = User::factory()->create();
        $target->assignRole($role);

        $this->assertTrue(StaffAdministration::mayManageUser($actor, $target));
    }

    public function test_critical_02_deactivate_staff_checks_expected_version_even_when_already_inactive(): void
    {
        $superAdminRole = Role::findOrCreate(StaffRole::SuperAdmin->value, 'staff');
        $actor = User::factory()->create(['is_active' => true]);
        $actor->assignRole($superAdminRole);

        $target = User::factory()->create(['is_active' => false]);

        $this->expectException(HttpException::class);
        app(DeactivateStaffUser::class)($actor, $target, expectedVersion: 999);
    }

    public function test_critical_02_update_staff_skips_duplicate_current_password_verification_when_preverified(): void
    {
        $superAdminRole = Role::findOrCreate(StaffRole::SuperAdmin->value, 'staff');
        $actor = User::factory()->create(['password' => Hash::make('AdminPass123!')]);
        $actor->assignRole($superAdminRole);

        $target = User::factory()->create();
        Role::findOrCreate('Cashier', 'staff');

        $key = 'staff:current-password:'.$actor->id;
        RateLimiter::clear($key);

        app(UpdateStaffUser::class)(
            $actor,
            $target,
            ['roles' => ['Cashier'], 'expected_version' => (int) $target->lock_version],
            verifiedPasswordHash: $actor->password,
            currentPassword: 'wrong-if-rechecked',
        );

        $this->assertSame(0, RateLimiter::attempts($key));
        $this->assertTrue($target->fresh()->hasRole('Cashier', 'staff'));
    }

    public function test_medium_04_update_branch_invalidates_branch_active_cache_and_wholesale_middleware_sets_guard(): void
    {
        $superAdminRole = Role::findOrCreate(StaffRole::SuperAdmin->value, 'staff');
        $actor = User::factory()->create(['is_active' => true]);
        $actor->assignRole($superAdminRole);

        $branch = Branch::factory()->create(['is_active' => true]);
        Cache::put('branch-active:'.$branch->id, true, 30);

        $this->assertTrue(app(BranchAccess::class)->isBranchActive($branch->id));

        app(UpdateBranch::class)($actor, $branch, ['is_active' => false, 'lock_version' => (int) $branch->lock_version]);
        $this->assertFalse(Cache::has('branch-active:'.$branch->id));

        $customer = Customer::factory()->create([
            'type' => CustomerType::Wholesale,
            'status' => CustomerStatus::Approved,
        ]);
        $token = $customer->createToken('wholesale', ['wholesale-token']);
        $customer->withAccessToken($token->accessToken);

        $request = Request::create('/api/v1/wholesale/orders', 'GET');
        $request->setUserResolver(fn (?string $guard = null) => $guard === 'sanctum' ? $customer : null);

        $response = (new EnsureWholesaleApproved)->handle($request, fn () => response()->json(['ok' => true]));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($customer->id, auth('customer')->id());
    }

    public function test_medium_06_audit_middleware_records_403_on_admin_get_requests(): void
    {
        $actor = User::factory()->create(['is_active' => true]);

        $request = Request::create('/api/v1/admin/reports/profit', 'GET');
        $request->setUserResolver(fn (?string $guard = null) => $guard === 'staff' ? $actor : null);

        $response = (new AuditStaffAdministration)->handle($request, fn () => response()->json(['message' => 'Forbidden'], 403));
        $this->assertSame(403, $response->getStatusCode());

        $this->assertTrue(
            DB::table('staff_audit_logs')
                ->where('actor_id', $actor->id)
                ->where('action', 'administration.denied')
                ->where('target_type', 'admin_resource')
                ->exists()
        );
    }

    public function test_medium_06_denial_audit_writes_are_rate_limited_per_staff_actor(): void
    {
        config(['auth.staff_admin_denial_audit_minute' => 1, 'cache.default' => 'array']);
        $actor = User::factory()->create(['is_active' => true]);
        RateLimiter::clear('staff:admin-denial-audit:'.$actor->id);

        foreach ([41, 42, 43] as $conversationId) {
            $request = Request::create('/api/v1/admin/ai/conversations/'.$conversationId, 'GET');
            $request->setUserResolver(fn (?string $guard = null) => $guard === 'staff' ? $actor : null);
            (new AuditStaffAdministration)->handle($request, fn () => response()->json(['message' => 'Forbidden'], 403));
        }

        $this->assertSame(1, DB::table('staff_audit_logs')
            ->where('actor_id', $actor->id)
            ->where('action', 'administration.denied')
            ->count());
    }

    public function test_medium_07_handoff_fingerprint_mismatch_does_not_burn_code(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $service = app(StaffHandoffService::class);

        $issueReq = Request::create('/api/v1/auth/login', 'POST', [], [], [], [
            'HTTP_USER_AGENT' => 'BrowserA/1.0',
        ]);
        $code = $service->issue($user, $issueReq);

        $wrongReq = Request::create('/api/v1/auth/handoff', 'POST', [], [], [], [
            'HTTP_USER_AGENT' => 'ScannerBot/1.0',
        ]);
        $this->assertNull($service->consume($code, $wrongReq));

        $goodReq = Request::create('/api/v1/auth/handoff', 'POST', [], [], [], [
            'HTTP_USER_AGENT' => 'BrowserA/1.0',
        ]);
        $consumed = $service->consume($code, $goodReq);
        $this->assertNotNull($consumed);
        $this->assertSame($user->id, $consumed['user_id']);
    }

    public function test_low_01_create_and_update_role_normalize_name_directly(): void
    {
        $superAdminRole = Role::findOrCreate(StaffRole::SuperAdmin->value, 'staff');
        $actor = User::factory()->create(['is_active' => true]);
        $actor->assignRole($superAdminRole);

        $role = app(CreateRole::class)($actor, ['name' => "  Warehouse\u{00A0}Manager  "]);
        $this->assertSame('Warehouse Manager', $role->name);

        $updated = app(UpdateRole::class)($actor, $role, [
            'name' => "  Senior\u{00A0}Manager  ",
            'expected_version' => (int) $role->lock_version,
        ]);
        $this->assertNull($updated);
        $this->assertSame('Senior Manager', $role->fresh()->name);
    }
}
