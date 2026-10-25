<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StoreBrandingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_public_branding_works_without_settings_or_authentication(): void
    {
        $this->getJson('/api/v1/store/branding')->assertOk()->assertExactJson([
            'data' => ['name' => 'Alfarid', 'logo' => null, 'email' => null, 'phone' => null, 'address' => null],
        ]);
    }

    public function test_public_response_exposes_only_identity_and_contact_fields(): void
    {
        $this->seed(SettingSeeder::class);

        $response = $this->getJson('/api/v1/store/branding')->assertOk()->assertExactJson([
            'data' => ['name' => 'Alfarid', 'logo' => null, 'email' => null, 'phone' => null, 'address' => null],
        ]);

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_admin_rename_is_visible_publicly_after_warming_the_cache(): void
    {
        $this->seed(SettingSeeder::class);
        $this->getJson('/api/v1/store/branding')->assertOk()->assertJsonPath('data.name', 'Alfarid');
        $staff = User::factory()->create();
        $staff->givePermissionTo(Permission::findOrCreate('settings.manage', 'staff'));
        $staff->givePermissionTo(Permission::findOrCreate('inventory.all_branches', 'staff'));
        $token = $staff->createToken('branding-test')->plainTextToken;
        Auth::forgetGuards();

        $this->withToken($token)->putJson('/api/v1/admin/settings', [
            'company_name' => 'الفريد للحقائب',
            'company_logo' => 'https://example.com/logo.png',
            'company_email' => 'info@example.com',
            'company_phone' => '+201000000000',
            'company_address' => 'Cairo',
            'expected' => ['company_name' => 'Alfarid', 'company_logo' => null, 'company_email' => null, 'company_phone' => null, 'company_address' => null],
        ])->assertOk()->assertJsonPath('data.company.company_name', 'الفريد للحقائب');

        $this->assertDatabaseHas('settings', ['key' => 'company_name', 'value' => 'الفريد للحقائب']);
        Auth::forgetGuards();
        $this->withHeader('Authorization', '')->getJson('/api/v1/store/branding')->assertOk()->assertExactJson([
            'data' => ['name' => 'الفريد للحقائب', 'logo' => 'https://example.com/logo.png', 'email' => 'info@example.com', 'phone' => '+201000000000', 'address' => 'Cairo'],
        ]);
    }

    public function test_blank_company_name_uses_the_default_identity(): void
    {
        Setting::query()->create(['group' => 'company', 'key' => 'company_name', 'value' => '  ', 'type' => 'string']);

        $this->getJson('/api/v1/store/branding')->assertOk()->assertJsonPath('data.name', 'Alfarid');
    }
}
