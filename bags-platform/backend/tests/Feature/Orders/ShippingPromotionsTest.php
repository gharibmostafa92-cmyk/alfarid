<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\City;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\ShippingCompany;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\OrderService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * المرحلة 8 (8.1 + 8.2): إدارة الشحن (شركات/مناطق/أسعار)، إنشاء البوالص
 * وتحويل الطلب إلى SHIPPED، وإدارة العروض والكوبونات وقواعد استخدامها.
 */
class ShippingPromotionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->admin = $this->staffWithPermissions([
            'shipping.view', 'shipping.create', 'shipping.update', 'shipping.delete',
            'promotions.view', 'promotions.create', 'promotions.update', 'promotions.delete',
            'coupons.view', 'coupons.create', 'coupons.update', 'coupons.delete',
            'orders.view', 'orders.update', 'orders.allocate',
        ]);
        $this->branch = Branch::factory()->create();
    }

    // ---------- 8.1: إدارة الشحن ----------

    public function test_shipping_companies_zones_rates_crud(): void
    {
        $company = $this->api('POST', '/api/v1/admin/shipping-companies', [
            'name' => 'بوسطة', 'code' => 'bosta',
            'api_credentials' => ['api_key' => 'secret-key'],
            'tracking_url_template' => 'https://bosta.example/track/{awb}',
        ])->assertCreated()
            ->assertJsonPath('data.code', 'bosta')
            ->assertJsonPath('data.has_api_credentials', true)
            ->json('data.id');
        // بيانات الاعتماد مشفرة في القاعدة ولا تُعرض
        $raw = ShippingCompany::findOrFail($company)->getRawOriginal('api_credentials');
        $this->assertStringNotContainsString('secret-key', (string) $raw);

        $city = City::factory()->create();
        $zone = $this->api('POST', '/api/v1/admin/shipping-zones', [
            'name' => 'القاهرة الكبرى', 'city_ids' => [$city->id],
        ])->assertCreated()->assertJsonCount(1, 'data.cities')->json('data.id');

        $this->api('POST', '/api/v1/admin/shipping-rates', [
            'shipping_company_id' => $company, 'shipping_zone_id' => $zone,
            'base_cost' => 60, 'free_shipping_threshold' => 1000, 'cod_fee' => 10,
            'estimated_days_min' => 1, 'estimated_days_max' => 3,
        ])->assertCreated()->assertJsonPath('data.base_cost', '60.00');

        // سعر مكرر لنفس الشركة والمنطقة مرفوض
        $this->api('POST', '/api/v1/admin/shipping-rates', [
            'shipping_company_id' => $company, 'shipping_zone_id' => $zone,
            'base_cost' => 70, 'estimated_days_min' => 1, 'estimated_days_max' => 2,
        ])->assertUnprocessable();

        $this->api('GET', '/api/v1/admin/shipping-companies')->assertOk()->assertJsonCount(1, 'data');
        $this->api('DELETE', "/api/v1/admin/shipping-companies/{$company}")->assertOk();
    }

    public function test_shipment_creation_ships_order_with_rate_cost_and_cod(): void
    {
        $variant = $this->purchasableVariant(200);
        $this->giveStock($variant, 10);
        $city = City::factory()->create(['shipping_cost' => 30]);
        $order = $this->orderFromCart($variant, 2, $city->id);
        app(OrderService::class)->changeStatus($order, OrderStatus::Confirmed, $this->admin);

        $company = ShippingCompany::factory()->create(['code' => 'bosta']);
        $zone = ShippingZone::factory()->create();
        $zone->cities()->sync([$city->id]);
        ShippingRate::factory()->create([
            'shipping_company_id' => $company->id, 'shipping_zone_id' => $zone->id,
            'base_cost' => 60,
        ]);

        $response = $this->api('POST', "/api/v1/admin/orders/{$order->id}/shipment", [
            'shipping_company_id' => $company->id,
        ])->assertOk()
            ->assertJsonPath('data.status', 'created')
            ->assertJsonPath('data.shipping_cost', '60.00')
            ->assertJsonPath('data.cod_amount', '430.00'); // 2×200 + شحن مدينة 30

        $this->assertStringStartsWith('BOSTA-', (string) $response->json('data.awb_number'));
        $this->assertSame(OrderStatus::Shipped, $order->fresh()->status);
        $level = $this->level($variant->id);
        $this->assertSame('0.0000', (string) $level->reserved);
        $this->assertSame('8.0000', (string) $level->on_hand);
        $this->assertSame(1, Shipment::query()->count());
    }

    public function test_shipment_on_pending_order_is_rejected_and_rolled_back(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 5);
        $order = $this->orderFromCart($variant, 1, null);
        $company = ShippingCompany::factory()->create();

        $this->api('POST', "/api/v1/admin/orders/{$order->id}/shipment", [
            'shipping_company_id' => $company->id,
        ])->assertUnprocessable();

        $this->assertSame(0, Shipment::query()->count());
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    // ---------- 8.2: العروض والكوبونات ----------

    public function test_promotions_and_coupons_crud(): void
    {
        $this->api('POST', '/api/v1/admin/promotions', [
            'name' => 'خصم الصيف', 'type' => 'percentage_discount', 'value' => 20,
            'applies_to' => 'categories', 'target_ids' => [1, 2],
        ])->assertCreated()->assertJsonPath('data.target_ids', [1, 2]);
        $this->api('GET', '/api/v1/admin/promotions')->assertOk()->assertJsonCount(1, 'data');

        $coupon = $this->api('POST', '/api/v1/admin/coupons', [
            'code' => 'summer25', 'type' => 'percentage', 'value' => 25,
            'max_discount_amount' => 100, 'usage_limit_per_customer' => 2,
        ])->assertCreated()
            ->assertJsonPath('data.code', 'SUMMER25') // يُخزن بأحرف كبيرة دائمًا
            ->assertJsonPath('data.usage_limit_per_customer', 2)
            ->json('data.id');

        $this->api('POST', '/api/v1/admin/coupons', [
            'code' => 'SUMMER25', 'type' => 'fixed', 'value' => 10,
        ])->assertUnprocessable(); // كود مكرر

        $this->api('DELETE', "/api/v1/admin/coupons/{$coupon}")->assertOk();
    }

    public function test_coupon_percentage_cap_and_per_customer_limit(): void
    {
        Coupon::factory()->create([
            'code' => 'HALF', 'type' => 'percentage', 'value' => 50,
            'max_discount_amount' => 30, 'usage_limit_per_customer' => 1,
        ]);
        $variant = $this->purchasableVariant(200);
        $this->giveStock($variant, 20);
        $customer = Customer::factory()->create();
        $token = $customer->createToken('store-token')->plainTextToken;
        $city = City::factory()->create(['shipping_cost' => 0]);

        $buyOnce = function () use ($token, $variant, $city): TestResponse {
            Auth::forgetGuards();
            $this->withToken($token)->postJson('/api/v1/store/cart/items', [
                'product_variant_id' => $variant->id, 'qty' => 1,
            ])->assertOk();
            Auth::forgetGuards();
            $this->withToken($token)->postJson('/api/v1/store/cart/coupon', ['code' => 'HALF'])
                ->assertOk()->assertJsonPath('data.coupon_discount', 30); // 50% من 200 = 100 تُسقف عند 30
            Auth::forgetGuards();

            return $this->withToken($token)->postJson('/api/v1/store/checkout', [
                'city_id' => $city->id, 'shipping_address_line' => 'عنوان', 'payment_method' => 'cod',
            ])->assertCreated()->assertJsonPath('data.discount_amount', '30.00');
        };

        $buyOnce();

        // الاستخدام الثاني لنفس العميل مرفوض (حد العميل = 1)
        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id, 'qty' => 1,
        ])->assertOk();
        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/v1/store/cart/coupon', ['code' => 'HALF'])
            ->assertUnprocessable();

        $this->assertSame(1, Coupon::query()->sole()->used_count);
        $this->assertSame(1, CouponUsage::query()->count());
    }

    public function test_free_shipping_coupon_waives_shipping_and_records_usage(): void
    {
        Coupon::factory()->create(['code' => 'FREESHIP', 'type' => 'free_shipping', 'value' => 0]);
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 5);
        $city = City::factory()->create(['shipping_cost' => 45]);
        $token = $this->getJson('/api/v1/store/cart/items')->json('data.cart_token');
        $headers = ['X-Cart-Session' => $token];

        $this->withHeaders($headers)->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id, 'qty' => 1,
        ])->assertOk();
        $this->withHeaders($headers)->postJson('/api/v1/store/cart/coupon', ['code' => 'FREESHIP'])->assertOk();

        $this->withHeaders($headers)->postJson('/api/v1/store/checkout', [
            'recipient_name' => 'ضيف', 'recipient_phone' => '01012345678',
            'city_id' => $city->id, 'shipping_address_line' => 'عنوان', 'payment_method' => 'cod',
        ])->assertCreated()
            ->assertJsonPath('data.shipping_cost', '0.00')
            ->assertJsonPath('data.grand_total', '100.00');

        $usage = CouponUsage::query()->sole();
        $this->assertSame('45.00', $usage->discount_amount); // الشحن المُعفى مسجل
    }

    // ---------- helpers ----------

    private function orderFromCart(ProductVariant $variant, float|int $qty, ?int $cityId): Order
    {
        $cart = Cart::create([]);
        $cart->items()->create(['product_variant_id' => $variant->id, 'qty' => $qty]);

        return app(OrderService::class)->createFromCart($cart, [
            'recipient_name' => 'عميل', 'recipient_phone' => '01012345678',
            'city_id' => $cityId, 'shipping_address_line' => 'عنوان',
            'payment_method' => 'cod',
        ]);
    }

    private function purchasableVariant(float $price): ProductVariant
    {
        $product = Product::factory()->create(['status' => 'published']);

        return ProductVariant::factory()->create([
            'product_id' => $product->id,
            'retail_price' => $price,
        ]);
    }

    private function giveStock(ProductVariant $variant, float|int $qty): void
    {
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id,
            'branch_id' => $this->branch->id,
            'type' => StockMovementType::AdjustmentIn,
            'affected_bucket' => StockBucket::OnHand,
            'quantity' => $qty,
            'created_by' => $this->admin->id,
        ]);
    }

    private function level(int $variantId): StockLevel
    {
        return StockLevel::query()
            ->where('product_variant_id', $variantId)
            ->where('branch_id', $this->branch->id)
            ->whereNull('location_id')
            ->firstOrFail();
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

    private function api(string $method, string $uri, array $payload = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($this->admin->createToken('t')->plainTextToken)
            ->json($method, $uri, $payload);
    }
}
