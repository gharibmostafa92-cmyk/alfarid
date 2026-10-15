<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Pricing;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\PriceListType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\PriceList;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PricingEngineTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ALL_PERMISSIONS = [
        'customer_groups.view', 'customer_groups.create', 'customer_groups.update', 'customer_groups.delete',
        'price_lists.view', 'price_lists.create', 'price_lists.update', 'price_lists.delete',
        'price_lists.import', 'price_lists.export',
    ];

    // ---------- PricingService: شجرة الأولويات ----------

    public function test_fallback_to_variant_base_price_for_every_channel(): void
    {
        $variant = $this->variant(100, 80);
        $service = app(PricingService::class);

        $guest = $service->resolve($variant);
        $this->assertSame(100.0, $guest['unit_price']);
        $this->assertSame('base_price', $guest['source']);
        $this->assertNull($guest['price_list_id']);

        $wholesale = $this->customer(CustomerType::Wholesale, CustomerStatus::Approved);
        $resolved = $service->resolve($variant, $wholesale);
        $this->assertSame(100.0, $resolved['unit_price']);
        $this->assertSame(100.0, $resolved['base_price']);
    }

    public function test_retail_list_and_quantity_tiers(): void
    {
        $variant = $this->variant();
        $list = $this->priceList(PriceListType::Retail);
        $list->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 90]);
        $list->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 10, 'price' => 70]);

        $service = app(PricingService::class);
        $this->assertSame(90.0, $service->resolve($variant, null, 1)['unit_price']);
        $this->assertSame(90.0, $service->resolve($variant, null, 9)['unit_price']);
        $tier = $service->resolve($variant, null, 10);
        $this->assertSame(70.0, $tier['unit_price']);
        $this->assertSame('retail', $tier['source']);
        $this->assertSame($list->id, $tier['price_list_id']);
    }

    public function test_wholesale_channel_requires_approved_status(): void
    {
        $variant = $this->variant();
        $list = $this->priceList(PriceListType::Wholesale);
        $list->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 60]);

        $service = app(PricingService::class);

        $pending = $this->customer(CustomerType::Wholesale, CustomerStatus::Pending);
        $this->assertSame(100.0, $service->resolve($variant, $pending)['unit_price']); // يرجع لسعر الأساس القطاعي

        $approved = $this->customer(CustomerType::Wholesale, CustomerStatus::Approved);
        $resolved = $service->resolve($variant, $approved);
        $this->assertSame(60.0, $resolved['unit_price']);
        $this->assertSame('wholesale', $resolved['source']);
    }

    public function test_group_beats_channel_and_specific_beats_group(): void
    {
        $variant = $this->variant();
        $group = CustomerGroup::query()->create(['name' => 'VIP', 'code' => 'VIP']);
        $customer = $this->customer(CustomerType::Wholesale, CustomerStatus::Approved, $group->id);

        $wholesale = $this->priceList(PriceListType::Wholesale);
        $wholesale->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 60]);

        $groupList = $this->priceList(PriceListType::CustomerGroup, customerGroupId: $group->id);
        $groupList->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 55]);

        $service = app(PricingService::class);
        $resolved = $service->resolve($variant, $customer);
        $this->assertSame(55.0, $resolved['unit_price']);
        $this->assertSame('customer_group', $resolved['source']);

        $specific = $this->priceList(PriceListType::CustomerSpecific, customerId: $customer->id);
        $specific->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 50]);

        $resolved = $service->resolve($variant, $customer);
        $this->assertSame(50.0, $resolved['unit_price']);
        $this->assertSame('customer_specific', $resolved['source']);
    }

    public function test_branch_specific_list_beats_global_and_validity_windows_apply(): void
    {
        $variant = $this->variant();
        $branch = Branch::factory()->create();

        $global = $this->priceList(PriceListType::Retail);
        $global->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 90]);

        $branchList = $this->priceList(PriceListType::Retail, branchId: $branch->id);
        $branchList->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 85]);

        $future = $this->priceList(PriceListType::Retail);
        $future->update(['starts_at' => now()->addDay()]);
        $future->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 10]);

        $inactive = $this->priceList(PriceListType::Retail);
        $inactive->update(['is_active' => false]);
        $inactive->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 5]);

        $service = app(PricingService::class);
        $this->assertSame(90.0, $service->resolve($variant)['unit_price']);
        $this->assertSame(85.0, $service->resolve($variant, null, 1, $branch->id)['unit_price']);
    }

    public function test_priority_breaks_ties_before_lowest_price(): void
    {
        $variant = $this->variant();

        $lowPriority = $this->priceList(PriceListType::Retail, priority: 1);
        $lowPriority->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 85]);

        $highPriority = $this->priceList(PriceListType::Retail, priority: 5);
        $highPriority->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 95]);

        $resolved = app(PricingService::class)->resolve($variant);
        $this->assertSame(95.0, $resolved['unit_price']);
        $this->assertSame($highPriority->id, $resolved['price_list_id']);
    }

    // ---------- Endpoints ----------

    public function test_customer_group_crud_and_delete_guard(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $admin->createToken('test')->plainTextToken;

        $group = $this->requestWithToken($token, 'POST', '/api/v1/admin/customer-groups', [
            'name' => 'كبار العملاء', 'code' => 'VIP', 'discount_percentage' => 10,
        ])->assertCreated()->json('data');

        $this->requestWithToken($token, 'POST', '/api/v1/admin/customer-groups', [
            'name' => 'نسخة', 'code' => 'VIP',
        ])->assertUnprocessable(); // كود مكرر

        $customer = $this->customer(CustomerType::Retail, CustomerStatus::Approved, $group['id']);
        $this->assertSame($group['id'], $customer->customer_group_id);

        $this->requestWithToken($token, 'DELETE', "/api/v1/admin/customer-groups/{$group['id']}")->assertUnprocessable();
    }

    public function test_price_list_type_owner_validation(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $admin->createToken('test')->plainTextToken;
        $customer = $this->customer(CustomerType::Retail, CustomerStatus::Approved);
        $group = CustomerGroup::query()->create(['name' => 'G', 'code' => 'G1']);

        // خاص بعميل من غير عميل
        $this->requestWithToken($token, 'POST', '/api/v1/admin/price-lists', [
            'name' => 'X', 'type' => 'customer_specific',
        ])->assertUnprocessable();

        // خاص بعميل مع مجموعة كمان
        $this->requestWithToken($token, 'POST', '/api/v1/admin/price-lists', [
            'name' => 'X', 'type' => 'customer_specific', 'customer_id' => $customer->id, 'customer_group_id' => $group->id,
        ])->assertUnprocessable();

        // قطاعي مع عميل
        $this->requestWithToken($token, 'POST', '/api/v1/admin/price-lists', [
            'name' => 'X', 'type' => 'retail', 'customer_id' => $customer->id,
        ])->assertUnprocessable();

        $created = $this->requestWithToken($token, 'POST', '/api/v1/admin/price-lists', [
            'name' => 'خاص', 'type' => 'customer_specific', 'customer_id' => $customer->id,
        ])->assertCreated()->json('data');

        // تعديل الاسم بس من غير إعادة إرسال العميل يعدّي
        $this->requestWithToken($token, 'PUT', "/api/v1/admin/price-lists/{$created['id']}", [
            'name' => 'اسم جديد',
        ])->assertOk()->assertJsonPath('data.name', 'اسم جديد');
    }

    public function test_price_list_items_crud_and_duplicate_tier_blocked(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $admin->createToken('test')->plainTextToken;
        $variant = $this->variant();
        $list = $this->priceList(PriceListType::Retail);

        $item = $this->requestWithToken($token, 'POST', "/api/v1/admin/price-lists/{$list->id}/items", [
            'product_variant_id' => $variant->id, 'min_qty' => 5, 'price' => 77,
        ])->assertCreated()->json('data');

        $this->requestWithToken($token, 'POST', "/api/v1/admin/price-lists/{$list->id}/items", [
            'product_variant_id' => $variant->id, 'min_qty' => 5, 'price' => 70,
        ])->assertUnprocessable(); // نفس الشريحة

        $this->requestWithToken($token, 'PUT', "/api/v1/admin/price-lists/{$list->id}/items/{$item['id']}", [
            'price' => 75,
        ])->assertOk()->assertJsonPath('data.price', '75.0000');

        $this->requestWithToken($token, 'DELETE', "/api/v1/admin/price-lists/{$list->id}/items/{$item['id']}")->assertOk();
        $this->assertDatabaseCount('price_list_items', 0);
    }

    public function test_import_and_export_csv_roundtrip(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $admin->createToken('test')->plainTextToken;
        $variant = $this->variant();
        $list = $this->priceList(PriceListType::Retail);
        $list->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 90]);

        // تصدير
        Auth::forgetGuards();
        $export = $this->withToken($token)->get("/api/v1/admin/price-lists/{$list->id}/export");
        $export->assertOk();
        $exportedCsv = $export->streamedContent();
        $this->assertStringContainsString('sku,variant_id,product_name,min_qty,price,starts_at,ends_at', $exportedCsv);
        $this->assertStringContainsString($variant->sku, $exportedCsv);

        // استيراد: تحديث سعر موجود + إنشاء شريحة جديدة + صف فاسد
        $csv = "sku,variant_id,product_name,min_qty,price,starts_at,ends_at\n"
            ."{$variant->sku},{$variant->id},,1,88,,\n"
            ."{$variant->sku},{$variant->id},,20,66,,\n"
            ."UNKNOWN-SKU,,,1,10,,\n";
        $file = UploadedFile::fake()->createWithContent('prices.csv', $csv);

        Auth::forgetGuards();
        $response = $this->withToken($token)->post("/api/v1/admin/price-lists/{$list->id}/import", ['file' => $file]);
        $response->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.skipped', 1);

        $this->assertSame(66.0, app(PricingService::class)->resolve($variant, null, 20)['unit_price']);
        $this->assertSame(88.0, app(PricingService::class)->resolve($variant, null, 1)['unit_price']);
    }

    public function test_pricing_endpoints_require_permissions(): void
    {
        $viewer = $this->staffWithPermissions([]);
        $token = $viewer->createToken('test')->plainTextToken;

        $this->requestWithToken($token, 'GET', '/api/v1/admin/customer-groups')->assertForbidden();
        $this->requestWithToken($token, 'GET', '/api/v1/admin/price-lists')->assertForbidden();
    }

    // ---------- Helpers ----------

    private function variant(float $retail = 100, float $wholesale = 80): ProductVariant
    {
        return ProductVariant::factory()->create(['retail_price' => $retail, 'wholesale_price' => $wholesale]);
    }

    private function priceList(PriceListType $type, ?int $customerId = null, ?int $customerGroupId = null, ?int $branchId = null, int $priority = 0): PriceList
    {
        return PriceList::query()->create([
            'name' => 'List '.uniqid(),
            'type' => $type,
            'customer_id' => $customerId,
            'customer_group_id' => $customerGroupId,
            'branch_id' => $branchId,
            'priority' => $priority,
        ]);
    }

    private function customer(CustomerType $type, CustomerStatus $status, ?int $groupId = null): Customer
    {
        return Customer::query()->create([
            'name' => 'عميل '.uniqid(),
            'email' => uniqid().'@example.com',
            'phone' => '010'.random_int(10000000, 99999999),
            'password' => Hash::make('password'),
            'type' => $type,
            'status' => $status,
            'customer_group_id' => $groupId,
        ]);
    }

    private function staffWithPermissions(array $permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'staff');
        }
        $staff = User::factory()->create();
        $staff->givePermissionTo($permissions);

        return $staff;
    }

    private function requestWithToken(string $token, string $method, string $uri, array $payload = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->json($method, $uri, $payload);
    }
}
