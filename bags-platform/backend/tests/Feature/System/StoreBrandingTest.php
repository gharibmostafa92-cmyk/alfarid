<?php

declare(strict_types=1);

namespace Tests\Feature\System;

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

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=60', $cacheControl);
        $this->assertStringContainsString('stale-while-revalidate=300', $cacheControl);
        $this->assertStringNotContainsString('no-store', $cacheControl);

        $vary = (string) $response->headers->get('Vary');
        $this->assertStringContainsString('Origin', $vary);
        $this->assertStringContainsString('Accept-Encoding', $vary);
        $this->assertStringContainsString('Authorization', $vary);
    }

    public function test_authenticated_branding_request_gets_private_no_store(): void
    {
        $this->seed(SettingSeeder::class);
        $user = User::factory()->create();
        $token = $user->createToken('test-branding')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/store/branding')->assertOk();
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
    }

    public function test_forget_taxonomy_caches_clears_cities_cache(): void
    {
        \Illuminate\Support\Facades\Cache::put('store:cities:v1:ar', ['test-city'], 3600);
        \Illuminate\Support\Facades\Cache::put('store:cities:v1:en', ['test-city'], 3600);
        $this->assertTrue(\Illuminate\Support\Facades\Cache::has('store:cities:v1:ar'));

        \App\Services\Catalog\StoreCatalogService::forgetTaxonomyCaches();

        $this->assertFalse(\Illuminate\Support\Facades\Cache::has('store:cities:v1:ar'));
        $this->assertFalse(\Illuminate\Support\Facades\Cache::has('store:cities:v1:en'));
    }

    public function test_public_branding_caches_response_and_invalidates_after_settings_update(): void
    {
        config(['cache.default' => 'branding_cache', 'cache.stores.branding_cache' => ['driver' => 'array']]);
        $this->seed(SettingSeeder::class);

        $this->getJson('/api/v1/store/branding')->assertOk()->assertJsonPath('data.name', 'Alfarid');
        app(\App\Services\Shared\SettingService::class)->forgetMemoryState();

        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        try {
            $this->getJson('/api/v1/store/branding')->assertOk()->assertJsonPath('data.name', 'Alfarid');
            $queries = \Illuminate\Support\Facades\DB::getQueryLog();
        } finally {
            \Illuminate\Support\Facades\DB::disableQueryLog();
        }
        $this->assertCount(0, array_filter($queries, fn ($q) => str_contains($q['query'], 'settings')));

        $staff = User::factory()->create();
        $staff->givePermissionTo(Permission::findOrCreate('settings.manage', 'staff'));
        $staff->givePermissionTo(Permission::findOrCreate('inventory.all_branches', 'staff'));
        $token = $staff->createToken('branding-cache-test')->plainTextToken;
        Auth::forgetGuards();

        $this->withToken($token)->putJson('/api/v1/admin/settings', [
            'company_name' => 'Brand Cached Updated',
            'expected' => ['company_name' => 'Alfarid'],
        ])->assertOk();

        Auth::forgetGuards();
        $this->withHeader('Authorization', '')->getJson('/api/v1/store/branding')->assertOk()
            ->assertJsonPath('data.name', 'Brand Cached Updated');
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

    public function test_invalid_private_json_cannot_break_public_branding(): void
    {
        $this->seed(SettingSeeder::class);
        Setting::query()->create(['group' => 'private', 'key' => 'broken_json', 'value' => '{invalid', 'type' => 'json']);

        $this->getJson('/api/v1/store/branding')->assertOk()->assertJsonPath('data.name', 'Alfarid');
    }
}
