<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\User;
use Database\Seeders\LocalAdminSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LocalAdminSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ?string $previousPassword;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousPassword = Env::get('LOCAL_ADMIN_PASSWORD');
        Env::getRepository()->set('LOCAL_ADMIN_PASSWORD', 'Local-admin-regression-2026');
    }

    protected function tearDown(): void
    {
        if ($this->previousPassword === null) {
            Env::getRepository()->clear('LOCAL_ADMIN_PASSWORD');
        } else {
            Env::getRepository()->set('LOCAL_ADMIN_PASSWORD', $this->previousPassword);
        }
        parent::tearDown();
    }

    public function test_fresh_admin_has_module_permissions_in_authenticated_api(): void
    {
        $this->seed(LocalAdminSeeder::class);
        $admin = User::where('email', 'admin@alfarid.local')->firstOrFail();
        $this->assertTrue($admin->hasRole(StaffRole::SuperAdmin->value, 'staff'));
        $this->assertTrue(Hash::check('Local-admin-regression-2026', $admin->password));
        $this->assertSame(Permission::where('guard_name', 'staff')->count(), $admin->getAllPermissions()->count());
        $response = $this->withToken($admin->createToken('test')->plainTextToken)->getJson('/api/v1/admin/me')->assertOk();
        foreach (['inventory.adjust', 'inventory.all_branches', 'inventory.view_cost', 'products.view_cost', 'transfers.approve', 'stocktakes.approve'] as $permission) {
            $this->assertTrue($admin->can($permission), $permission);
            $this->assertContains($permission, $response->json('data.permissions'));
        }
    }

    public function test_existing_admin_permissions_are_repaired_without_resetting_credentials(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['email' => 'admin@alfarid.local']);
        $admin->assignRole(StaffRole::SuperAdmin->value);
        $password = $admin->password;
        $token = $admin->createToken('existing-session')->plainTextToken;
        $this->assertFalse($admin->can('inventory.adjust'));

        $this->seed(LocalAdminSeeder::class);
        $this->seed(LocalAdminSeeder::class);

        $admin = $admin->fresh();
        $this->assertSame($password, $admin->password);
        $this->assertSame(1, User::where('email', 'admin@alfarid.local')->count());
        $this->assertTrue($admin->can('inventory.adjust'));
        $this->withToken($token)->getJson('/api/v1/admin/me')->assertOk()
            ->assertJsonFragment(['name' => StaffRole::SuperAdmin->value]);
    }

    public function test_existing_non_admin_account_is_never_promoted(): void
    {
        $user = User::factory()->create(['email' => 'admin@alfarid.local']);
        try {
            $this->seed(LocalAdminSeeder::class);
            $this->fail('An existing non-admin account must not be promoted.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('already in use', $exception->getMessage());
        }
        $this->assertFalse($user->fresh()->hasRole(StaffRole::SuperAdmin->value));
    }
}
