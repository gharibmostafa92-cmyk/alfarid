<?php

declare(strict_types=1);

namespace Tests\Feature\Staff;

use App\Actions\Staff\UpdateRole;
use App\Actions\Staff\UpdateStaffUser;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class Task3Slice4OptimisticLockingTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function superAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('Super Admin', 'staff'));

        return $admin;
    }

    public function test_staff_update_without_expected_version_is_rejected(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create();

        try {
            app(UpdateStaffUser::class)($admin, $target, ['name' => 'Silent Overwrite'], ['channel' => 'test']);
            $this->fail('Blind write without expected_version must be rejected with 409.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertNotSame('Silent Overwrite', $target->fresh()->name);
    }

    public function test_role_update_without_expected_version_is_rejected(): void
    {
        $admin = $this->superAdmin();
        Permission::findOrCreate('users.view', 'staff');
        $role = Role::query()->create(['name' => 'task3-lock-version', 'guard_name' => 'staff']);

        try {
            app(UpdateRole::class)($admin, $role, ['permissions' => ['users.view']]);
            $this->fail('Blind write without expected_version must be rejected with 409.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame([], $role->fresh()->permissions()->pluck('name')->all());
    }

    public function test_concurrent_edit_with_stale_version_is_rejected(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create();
        $staleVersion = (int) $target->lock_version;

        app(UpdateStaffUser::class)(
            $admin,
            $target,
            ['name' => 'First Writer', 'expected_version' => $staleVersion],
            ['channel' => 'test']
        );

        $this->assertSame('First Writer', $target->fresh()->name);

        try {
            app(UpdateStaffUser::class)(
                $admin,
                $target->fresh(),
                ['name' => 'Stale Writer', 'expected_version' => $staleVersion],
                ['channel' => 'test']
            );
            $this->fail('Stale expected_version must be rejected with 409.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame('First Writer', $target->fresh()->name);
    }
}
