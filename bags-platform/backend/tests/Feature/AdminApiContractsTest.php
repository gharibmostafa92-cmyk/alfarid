<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\LocalAdminSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminApiContractsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_contracts_and_optionally_export_fixtures(): void
    {
        // Isolated test database: never reset the developer's application database.
        putenv('LOCAL_ADMIN_PASSWORD=Contract-test-password-42');
        try {
            $this->seed([LocalAdminSeeder::class, SettingSeeder::class]);
        } finally {
            putenv('LOCAL_ADMIN_PASSWORD');
        }
        $admin = User::query()->where('email', 'admin@alfarid.local')->firstOrFail();
        $branch = Branch::factory()->create(['name' => 'فرع الاختبارات']);
        $role = Role::create(['name' => 'مشرف الاختبارات', 'guard_name' => 'staff']);
        $role->givePermissionTo('inventory.view');
        User::factory()->create(['name' => 'موظف الاختبارات', 'email' => 'staff@example.test', 'branch_id' => $branch->id])->assignRole($role);
        $token = $admin->createToken('contract-test')->plainTextToken;
        $paths = ['me' => '/me', 'users' => '/users?per_page=8', 'roles' => '/roles?per_page=100', 'permissions' => '/permissions', 'branches' => '/branches?per_page=100', 'settings' => '/settings'];
        foreach ($paths as $name => $path) {
            Auth::forgetGuards();
            $response = $this->withToken($token)->getJson('/api/v1/admin'.$path)->assertOk()->assertJsonStructure(['data']);
            if (getenv('EXPORT_ADMIN_FIXTURES') === '1') {
                $directory = base_path('../frontend/apps/backoffice/src/pages/admin/admin-mock/api/__fixtures__');
                if (! is_dir($directory)) {
                    mkdir($directory, 0755, true);
                }
                file_put_contents($directory.'/'.$name.'.json', json_encode($response->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
            }
        }
        $this->assertTrue($admin->hasRole(StaffRole::SuperAdmin->value));
    }
}
