<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\SettingService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AdminSettingsEndpointsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_read_grouped_settings_with_typed_values(): void
    {
        $this->seedSettings();
        $token = $this->staffWithSettingsPermission()->createToken('settings-test')->plainTextToken;

        $this->requestWithToken($token, 'GET', '/api/v1/admin/settings')
            ->assertOk()
            ->assertJsonPath('data.tax.tax_enabled', false)
            ->assertJsonPath('data.tax.default_tax_rate', 14)
            ->assertJsonPath('data.numbering.invoice_next_number', 1000)
            ->assertJsonPath('data.company.company_name', 'Alfarid');
    }

    public function test_admin_can_update_settings_and_cache_is_invalidated(): void
    {
        $this->seedSettings();
        $service = app(SettingService::class);
        $this->assertSame(14.0, $service->get('default_tax_rate'));
        $token = $this->staffWithSettingsPermission()->createToken('settings-test')->plainTextToken;

        $this->requestWithToken($token, 'PUT', '/api/v1/admin/settings', [
            'company_name' => 'Alfarid Trading',
            'tax_enabled' => true,
            'default_tax_rate' => 15,
            'order_next_number' => 2000,
        ])->assertOk()
            ->assertJsonPath('data.company.company_name', 'Alfarid Trading')
            ->assertJsonPath('data.tax.tax_enabled', true)
            ->assertJsonPath('data.tax.default_tax_rate', 15)
            ->assertJsonPath('data.numbering.order_next_number', 2000);

        $this->assertSame('Alfarid Trading', $service->get('company_name'));
        $this->assertDatabaseHas('settings', ['key' => 'tax_enabled', 'value' => '1']);
    }

    public function test_update_validates_tax_rates_counters_and_unknown_keys(): void
    {
        $this->seedSettings();
        $token = $this->staffWithSettingsPermission()->createToken('settings-test')->plainTextToken;

        $this->requestWithToken($token, 'PUT', '/api/v1/admin/settings', [
            'default_tax_rate' => 101,
            'invoice_next_number' => 0,
            'unknown_option' => 'value',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['default_tax_rate', 'invoice_next_number', 'unknown_option']);
    }

    public function test_settings_update_ignores_query_parameters_and_allows_clearing_prefixes(): void
    {
        $this->seedSettings();
        $token = $this->staffWithSettingsPermission()->createToken('settings-test')->plainTextToken;

        $this->requestWithToken($token, 'PUT', '/api/v1/admin/settings?lang=ar&_t=123', [
            'invoice_prefix' => '',
        ])->assertOk()
            ->assertJsonPath('data.numbering.invoice_prefix', '');

        $this->assertDatabaseHas('settings', ['key' => 'invoice_prefix', 'value' => '']);
    }

    public function test_update_returns_conflict_instead_of_server_error_if_setting_row_is_missing(): void
    {
        $this->seedSettings();
        Setting::query()->where('key', 'company_name')->delete();
        $token = $this->staffWithSettingsPermission()->createToken('settings-test')->plainTextToken;

        $this->requestWithToken($token, 'PUT', '/api/v1/admin/settings', [
            'company_name' => 'New Name',
            'tax_enabled' => true,
        ])->assertStatus(409);

        $this->assertDatabaseMissing('settings', ['key' => 'company_name']);
        $this->assertDatabaseHas('settings', ['key' => 'tax_enabled', 'value' => '0']);
    }

    public function test_number_generation_increments_the_counter_and_applies_padding(): void
    {
        $this->seedSettings();
        $this->restartDocumentSequences();
        $service = app(SettingService::class);
        $service->getAllGrouped();
        $this->assertTrue(Cache::has('app_settings_static'));

        $this->assertSame('ORD-001000', $service->generateNextNumber('order'));
        $this->assertTrue(Cache::has('app_settings_static'));
        $this->assertSame('ORD-001001', $service->generateNextNumber('order'));
        $this->assertSame(1002, $service->get('order_next_number'));
        $this->assertSame('PO-001000', $service->generateNextNumber('purchase_order'));
        $this->assertSame('QT-001000', $service->generateNextNumber('quotation'));
    }

    public function test_purchase_order_and_quotation_padding_can_be_configured_and_validated(): void
    {
        $this->seedSettings();
        $this->restartDocumentSequences();
        $token = $this->staffWithSettingsPermission()->createToken('settings-test')->plainTextToken;

        $this->requestWithToken($token, 'PUT', '/api/v1/admin/settings', [
            'purchase_order_padding' => 8,
            'quotation_padding' => 7,
        ])->assertOk()
            ->assertJsonPath('data.numbering.purchase_order_padding', 8)
            ->assertJsonPath('data.numbering.quotation_padding', 7);

        $service = app(SettingService::class);
        $this->assertSame('PO-00001000', $service->generateNextNumber('purchase_order'));
        $this->assertSame('QT-0001000', $service->generateNextNumber('quotation'));
        // العدّاد المعروض هو قيمة التسلسل التالية لا صف الإعدادات (H2)
        $this->assertSame(1001, $service->get('purchase_order_next_number'));
        $this->assertSame(1001, $service->get('quotation_next_number'));

        $this->requestWithToken($token, 'PUT', '/api/v1/admin/settings', [
            'purchase_order_padding' => 0,
            'quotation_padding' => 21,
        ])->assertUnprocessable()->assertJsonValidationErrors(['purchase_order_padding', 'quotation_padding']);
    }

    public function test_settings_query_parameters_cannot_change_setting_values(): void
    {
        $this->seedSettings();
        $token = $this->staffWithSettingsPermission()->createToken('settings-test')->plainTextToken;

        $this->requestWithToken($token, 'PUT', '/api/v1/admin/settings?company_name=Injected&tax_enabled=1', [
            'invoice_prefix' => 'BILL-',
        ])->assertOk()
            ->assertJsonPath('data.company.company_name', 'Alfarid')
            ->assertJsonPath('data.tax.tax_enabled', false);

        $this->assertDatabaseHas('settings', ['key' => 'company_name', 'value' => 'Alfarid']);
        $this->assertDatabaseHas('settings', ['key' => 'invoice_prefix', 'value' => 'BILL-']);
    }

    public function test_settings_update_cannot_decrease_a_numbering_counter(): void
    {
        $this->seedSettings();
        $this->restartDocumentSequences();
        $service = app(SettingService::class);
        $service->generateNextNumber('order');
        $token = $this->staffWithSettingsPermission()->createToken('settings-test')->plainTextToken;

        $this->requestWithToken($token, 'PUT', '/api/v1/admin/settings', [
            'order_next_number' => 1000,
        ])->assertOk()
            ->assertJsonPath('data.numbering.order_next_number', 1001);

        $this->assertDatabaseHas('settings', ['key' => 'order_next_number', 'value' => '1001']);
    }

    private function seedSettings(): void
    {
        $this->seed(SettingSeeder::class);
    }

    /**
     * منذ H2 تُرقَّم المستندات الداخلية بتسلسلات PostgreSQL لا بصف العداد،
     * والتسلسل لا يتراجع مع معاملات الاختبار — تُعاد تهيئته لنقطة معروفة
     * حتى تبقى تأكيدات القيم المطلقة حتمية.
     */
    private function restartDocumentSequences(): void
    {
        foreach (['order', 'purchase_order', 'quotation'] as $type) {
            DB::statement("ALTER SEQUENCE document_{$type}_number_seq RESTART WITH 1000");
        }
    }

    private function staffWithSettingsPermission(): User
    {
        $permission = Permission::findOrCreate('settings.manage', 'staff');
        $staff = User::factory()->create();
        $staff->givePermissionTo($permission);

        return $staff;
    }

    /** @param array<string, mixed> $payload */
    private function requestWithToken(string $token, string $method, string $uri, array $payload = [])
    {
        Auth::forgetGuards();

        return $this->withToken($token)->json($method, $uri, $payload);
    }
}
