<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Branch;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\TransientToken;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Task 8 (security audit) slice 1: staff guard token-type enforcement and settings exposure.
 */
class Task8Slice1AuthAndSettingsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function staffWithPermissions(array $permissions, ?int $branchId = null): User
    {
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'staff');
        }
        $staff = User::factory()->create(['is_active' => true, 'branch_id' => $branchId]);
        $staff->givePermissionTo($permissions);

        return $staff;
    }

    public function test_staff_middleware_rejects_transient_session_tokens(): void
    {
        $staff = $this->staffWithPermissions(['users.view']);

        $staff->withAccessToken(new TransientToken);
        app('auth')->guard('sanctum')->setUser($staff);

        $this->getJson('/api/v1/admin/me')->assertForbidden();
    }

    public function test_settings_index_requires_central_scope_and_hides_ai_keys_without_ai_permission(): void
    {
        Setting::create(['group' => 'ai', 'key' => 'ai_finance_high_risk_amount', 'value' => '5000', 'type' => 'integer']);
        Setting::create(['group' => 'company', 'key' => 'company_name', 'value' => 'VARYA', 'type' => 'string']);

        $branch = Branch::factory()->create(['is_active' => true]);
        $branchStaff = $this->staffWithPermissions(['settings.view'], $branch->id);
        $branchToken = $branchStaff->createToken('test', ['*'])->plainTextToken;

        $this->withToken($branchToken)->getJson('/api/v1/admin/settings')->assertForbidden();

        $central = $this->staffWithPermissions(['settings.view', 'inventory.all_branches']);
        $centralToken = $central->createToken('test', ['*'])->plainTextToken;

        Auth::forgetGuards();
        $response = $this->withToken($centralToken)->getJson('/api/v1/admin/settings')->assertOk();
        $this->assertArrayNotHasKey('ai', $response->json('data'));
        $this->assertSame('VARYA', $response->json('data.company.company_name'));

        Permission::findOrCreate('ai_agent.settings', 'staff');
        $central->givePermissionTo('ai_agent.settings');

        Auth::forgetGuards();
        $this->withToken($centralToken)->getJson('/api/v1/admin/settings')
            ->assertOk()
            ->assertJsonPath('data.ai.ai_finance_high_risk_amount', 5000);
    }
}

