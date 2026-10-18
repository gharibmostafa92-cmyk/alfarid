<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\City;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\ShippingCompany;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ShipmentService;
use Database\Seeders\PromotionPermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\ShippingPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * اختبارات انحدار جولة آثار الطلب والمال (M11–M19 وL3–L6).
 */
class OrderEffectsReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PromotionPermissionSeeder::class);
        $this->seed(ShippingPermissionSeeder::class);
        $this->staff = User::factory()->create();
        $this->staff->assignRole('Super Admin');
        $this->branch = Branch::factory()->create();
    }

    private function purchasableVariant(float $price = 100): ProductVariant
    {
        $product = Product::factory()->create(['status' => 'published']);
        $variant = $product->variants()->first() ?? $product->variants()->create([
            'name_ar' => 'قياسي', 'sku' => 'SKU-'.uniqid(), 'retail_price' => $price, 'is_active' => true,
        ]);
        if ($variant->retail_price === null) {
            $variant->update(['retail_price' => $price]);
        }

        return $variant;
    }

    private function giveStock(ProductVariant $variant, float $qty): StockLevel
    {
        $location = Location::factory()->create(['branch_id' => $this->branch->id]);

        return StockLevel::query()->create([
            'branch_id' => $this->branch->id, 'location_id' => $location->id,
            'product_variant_id' => $variant->id, 'on_hand' => $qty, 'reserved' => 0,
        ]);
    }

    private function guestCheckout(ProductVariant $variant, float $qty, ?City $city = null, string $payment = 'cod'): Order
    {
        $city ??= City::factory()->create(['shipping_cost' => 0]);
        $token = $this->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id, 'qty' => $qty,
        ])->assertOk()->json('data.cart_token');
        $id = $this->withHeaders(['X-Cart-Session' => $token])->postJson('/api/v1/store/checkout', [
            'recipient_name' => 'ضيف', 'recipient_phone' => '01012345678',
            'city_id' => $city->id, 'shipping_address_line' => 'عنوان', 'payment_method' => $payment,
        ])->assertCreated()->json('data.id');

        return Order::findOrFail($id);
    }

    private function shipCodOrder(): array
    {
        $variant = $this->purchasableVariant(100);
        $level = $this->giveStock($variant, 10);
        $order = $this->guestCheckout($variant, 3);
        app(OrderService::class)->changeStatus($order, OrderStatus::Confirmed, $this->staff);
        $company = ShippingCompany::factory()->create([
            'code' => 'SHIPX', 'api_credentials' => ['webhook_secret' => 'ship-secret'],
        ]);
        $shipment = app(ShipmentService::class)->createForOrder($order->fresh(), $company->id, null, $this->staff);

        return [$order->fresh(), $shipment, $level, $variant];
    }

    private function shippingWebhook(Shipment $shipment, string $status, string $eventId): void
    {
        $body = json_encode([
            'event_id' => $eventId, 'awb' => $shipment->awb_number, 'status' => $status,
        ]);
        $this->call('POST', '/api/v1/webhooks/shipping/SHIPX', [], [], [],
            ['CONTENT_TYPE' => 'application/json',
                'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', (string) $body, 'ship-secret')], (string) $body)
            ->assertOk();
    }

    // ---------- M11 ----------

    public function test_returned_shipment_returns_order_and_restocks(): void
    {
        [$order, $shipment, $level, $variant] = $this->shipCodOrder();
        $this->assertSame('7.0000', (string) $level->fresh()->on_hand);
        $this->assertSame(3, (int) $variant->product->fresh()->sales_count);

        // إشعار المرتجع وحده لا يعيد البضاعة للمخزون (F-15): الإشعار يعني
        // بدء الإرجاع — الطلب يُعلَّم للانتباه ويبقى مشحونًا والمخزون كما هو
        $this->shippingWebhook($shipment, 'returned', 'evt-ret-1');

        $this->assertSame(OrderStatus::Shipped, $order->fresh()->status);
        $this->assertTrue($order->fresh()->needs_attention);
        $this->assertSame('7.0000', (string) $level->fresh()->on_hand);
        $this->assertFalse(StockMovement::query()
            ->where('reference_id', $order->id)->where('type', StockMovementType::SaleReturn)->exists());

        // الاستلام الفعلي في المستودع: الأدمن ينقل الطلب Returned فيدخل
        // المخزون عندها فقط بحركات sale_return ويُنقص sales_count (M13)
        $token = $this->staff->createToken('t')->plainTextToken;
        $this->withToken($token)->patchJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => 'returned',
        ])->assertOk();

        $this->assertSame(OrderStatus::Returned, $order->fresh()->status);
        $this->assertSame('10.0000', (string) $level->fresh()->on_hand);
        $this->assertSame(0, (int) $variant->product->fresh()->sales_count);
        $this->assertTrue(StockMovement::query()
            ->where('reference_id', $order->id)->where('type', StockMovementType::SaleReturn)->exists());
        // مرتجع لم يُسلَّم فعليًا: لا تحصيل COD
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_delivered_webhook_on_cancelled_order_does_not_mark_cod_paid(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 5);
        $order = $this->guestCheckout($variant, 1);
        app(OrderService::class)->changeStatus($order, OrderStatus::Cancelled, $this->staff);
        $company = ShippingCompany::factory()->create([
            'code' => 'SHIPX', 'api_credentials' => ['webhook_secret' => 'ship-secret'],
        ]);
        $shipment = Shipment::factory()->create([
            'order_id' => $order->id, 'shipping_company_id' => $company->id, 'status' => 'created',
        ]);

        $this->shippingWebhook($shipment, 'delivered', 'evt-del-cancelled');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    // ---------- M13 ----------

    public function test_cancelling_order_reverses_coupon_usage_so_customer_can_reuse_it(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 10);
        Coupon::factory()->create(['code' => 'ONCE', 'type' => 'fixed', 'value' => 10, 'usage_limit_per_customer' => 1]);
        $customer = Customer::factory()->create();
        $token = $customer->createToken('t')->plainTextToken;
        $city = City::factory()->create(['shipping_cost' => 0]);

        $buy = function () use ($token, $variant, $city): int {
            $this->withToken($token)->postJson('/api/v1/store/cart/items', [
                'product_variant_id' => $variant->id, 'qty' => 1,
            ])->assertOk();
            $this->withToken($token)->postJson('/api/v1/store/cart/coupon', ['code' => 'ONCE'])->assertOk();

            return $this->withToken($token)->postJson('/api/v1/store/checkout', [
                'city_id' => $city->id, 'shipping_address_line' => 'عنوان', 'payment_method' => 'cod',
            ])->assertCreated()->json('data.id');
        };

        $orderId = $buy();
        $coupon = Coupon::where('code', 'ONCE')->first();
        $this->assertSame(1, (int) $coupon->used_count);
        $this->assertSame(1, $coupon->usages()->count());

        $this->withToken($token)->postJson("/api/v1/store/account/orders/{$orderId}/cancel")->assertOk();

        $this->assertSame(0, (int) $coupon->fresh()->used_count);
        $this->assertSame(0, $coupon->usages()->count());

        // الكوبون صالح للاستخدام من جديد بعد الإلغاء
        $this->assertNotNull($buy());
        $this->assertSame(1, (int) $coupon->fresh()->used_count);
    }

    // ---------- M12 ----------

    public function test_checkout_uses_zone_rate_threshold_and_cod_fee(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 20);
        $city = City::factory()->create(['shipping_cost' => 30]);
        $company = ShippingCompany::factory()->create(['code' => 'RATECO']);
        $zone = ShippingZone::factory()->create();
        $zone->cities()->attach($city->id);
        ShippingRate::create([
            'shipping_company_id' => $company->id, 'shipping_zone_id' => $zone->id,
            'base_cost' => 50, 'free_shipping_threshold' => 500, 'cod_fee' => 15,
            'estimated_days_min' => 2, 'estimated_days_max' => 4,
        ]);

        $small = $this->guestCheckout($variant, 1, $city);
        $this->assertSame('50.00', (string) $small->shipping_cost);
        $this->assertSame('15.00', (string) $small->cod_fee);
        $this->assertSame('165.00', (string) $small->grand_total);

        $big = $this->guestCheckout($variant, 6, $city);
        $this->assertSame('0.00', (string) $big->shipping_cost); // فوق حد الشحن المجاني
        $this->assertSame('15.00', (string) $big->cod_fee);
        $this->assertSame('615.00', (string) $big->grand_total);

        $options = $this->postJson('/api/v1/store/checkout/shipping-options', ['city_id' => $city->id])
            ->assertOk()->json('data.options.0');
        $this->assertSame(50.0, (float) $options['cost']);
        $this->assertSame(15.0, (float) $options['cod_fee']);
    }

    // ---------- M14 ----------

    public function test_checkout_rejects_variant_deactivated_after_cart_add(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 5);
        $city = City::factory()->create(['shipping_cost' => 0]);
        $token = $this->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id, 'qty' => 1,
        ])->assertOk()->json('data.cart_token');

        $variant->update(['is_active' => false]);

        $this->withHeaders(['X-Cart-Session' => $token])->postJson('/api/v1/store/checkout', [
            'recipient_name' => 'ضيف', 'recipient_phone' => '01012345678',
            'city_id' => $city->id, 'shipping_address_line' => 'عنوان', 'payment_method' => 'cod',
        ])->assertUnprocessable();
        $this->assertSame(0, Order::count());
    }

    // ---------- M17 ----------

    public function test_coupon_discount_is_distributed_across_items(): void
    {
        $v1 = $this->purchasableVariant(100);
        $v2 = $this->purchasableVariant(300);
        $this->giveStock($v1, 5);
        $this->giveStock($v2, 5);
        Coupon::factory()->create(['code' => 'TENPCT', 'type' => 'percentage', 'value' => 10]);
        $city = City::factory()->create(['shipping_cost' => 0]);

        $token = $this->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $v1->id, 'qty' => 1,
        ])->assertOk()->json('data.cart_token');
        $headers = ['X-Cart-Session' => $token];
        $this->withHeaders($headers)->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $v2->id, 'qty' => 1,
        ])->assertOk();
        $this->withHeaders($headers)->postJson('/api/v1/store/cart/coupon', ['code' => 'TENPCT'])->assertOk();
        $orderId = $this->withHeaders($headers)->postJson('/api/v1/store/checkout', [
            'recipient_name' => 'ضيف', 'recipient_phone' => '01012345678',
            'city_id' => $city->id, 'shipping_address_line' => 'عنوان', 'payment_method' => 'cod',
        ])->assertCreated()->json('data.id');

        $order = Order::with('items')->findOrFail($orderId);
        $this->assertSame('40.00', (string) $order->discount_amount);
        $shares = $order->items->sortBy('unit_price')->pluck('discount_amount')->map(fn ($v) => (string) $v)->all();
        $this->assertSame(['10.00', '30.00'], array_values($shares));
        $this->assertSame(40.0, (float) $order->items->sum('discount_amount'));
    }

    // ---------- M18 + L3 + L6 ----------

    public function test_percentage_coupon_over_100_is_rejected_and_shipped_needs_shipment_and_qty_cap(): void
    {
        $token = $this->staff->createToken('t')->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/admin/coupons', [
            'code' => 'TOOMUCH', 'type' => 'percentage', 'value' => 150,
        ])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/admin/coupons', [
            'code' => 'FULL', 'type' => 'percentage', 'value' => 100,
        ])->assertCreated();

        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 5);
        $order = $this->guestCheckout($variant, 1);
        app(OrderService::class)->changeStatus($order, OrderStatus::Confirmed, $this->staff);
        $this->withToken($token)->patchJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => 'shipped',
        ])->assertUnprocessable();

        $this->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id, 'qty' => 20000,
        ])->assertUnprocessable();
    }

    // ---------- M19 ----------

    public function test_paid_but_unconfirmable_order_is_flagged_and_system_user_owns_auto_movements(): void
    {
        config(['webhooks.payment.testpay' => ['secret' => 'pay-secret', 'signature_header' => 'X-Webhook-Signature']]);
        $variant = $this->purchasableVariant(100);
        // طلب بإجمالي معروف وبند بلا أي مخزون: التأكيد التلقائي سيفشل في التوزيع
        $order = Order::factory()->create([
            'payment_method' => 'card', 'payment_status' => 'unpaid', 'grand_total' => 100,
        ]);
        $order->items()->create([
            'product_variant_id' => $variant->id, 'product_name_snapshot' => 'x', 'sku_snapshot' => 's',
            'qty' => 1, 'unit_price' => 100, 'line_total' => 100,
        ]);

        $body = json_encode(['transaction_id' => 'tx-flag-1', 'success' => true, 'order_number' => $order->order_number]);
        $this->call('POST', '/api/v1/webhooks/payment/testpay', [], [], [],
            ['CONTENT_TYPE' => 'application/json',
                'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', (string) $body, 'pay-secret')], (string) $body)
            ->assertOk();

        $fresh = $order->fresh();
        $this->assertSame('paid', $fresh->payment_status);
        $this->assertSame(OrderStatus::Pending, $fresh->status);
        $this->assertTrue((bool) $fresh->needs_attention);
        $this->withToken($this->staff->createToken('t')->plainTextToken)
            ->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertOk()->assertJsonPath('data.needs_attention', true);

        // حركات النظام تُنسب لمستخدم النظام المخصص لا لأول Super Admin
        $system = User::query()->where('email', 'system@alfarid.local')->first();
        $this->assertNotNull($system);
        $this->giveStock($variant, 5);
        $customerOrder = $this->guestCheckout($variant, 1);
        app(OrderService::class)->changeStatus($customerOrder, OrderStatus::Confirmed, $this->staff);
        $customer = Customer::factory()->create();
        $customerOrder->update(['customer_id' => $customer->id]);
        app(OrderService::class)->changeStatus($customerOrder->fresh(), OrderStatus::Cancelled, $customer);
        $release = StockMovement::query()->where('reference_id', $customerOrder->id)
            ->where('type', StockMovementType::ReleaseReserve)->first();
        $this->assertSame($system->id, (int) $release->created_by);
    }

    // ---------- L4 ----------

    public function test_tracking_url_encodes_awb(): void
    {
        $company = ShippingCompany::factory()->create([
            'code' => 'TRK', 'tracking_url_template' => 'https://track.example/{awb}',
        ]);
        $shipment = Shipment::factory()->create([
            'shipping_company_id' => $company->id, 'awb_number' => 'AB C/1',
        ]);
        $this->assertSame('https://track.example/AB%20C%2F1', $shipment->trackingUrl());
    }
}
