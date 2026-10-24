<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\City;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\ShippingCompany;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\OrderService;
use Database\Seeders\OrderPermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\ShippingPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * انحدار F-11..F-17: إلغاء بفحص مقفول، شراء idempotent، آثار مالية
 * مركزية، مرتجع بلا إعادة فورية، بوليصة بديلة، وسقف كوبون الزوار.
 */
class OrderIntegrityReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, OrderPermissionSeeder::class, ShippingPermissionSeeder::class, SettingSeeder::class]);
        $this->staff = User::factory()->create();
        $this->staff->assignRole('Super Admin');
        $this->branch = Branch::factory()->create(['city' => 'القاهرة']);
        $this->branch->locations()->firstOrCreate(['code' => 'S-01'], ['name' => 'رف', 'type' => 'shelf']);
    }

    private function staffToken(): string
    {
        return $this->staff->createToken('t')->plainTextToken;
    }

    private function variant(float $price = 100): ProductVariant
    {
        $product = Product::factory()->create(['status' => 'published']);

        return $product->variants()->create([
            'name_ar' => 'قياسي', 'sku' => 'SKU-'.uniqid(), 'retail_price' => $price, 'is_active' => true,
        ]);
    }

    private function giveStock(ProductVariant $variant, float $qty): StockLevel
    {
        return StockLevel::unguarded(fn () => StockLevel::updateOrCreate(
            ['branch_id' => $this->branch->id, 'location_id' => $this->branch->locations()->firstOrFail()->id, 'product_variant_id' => $variant->id],
            ['on_hand' => $qty],
        ));
    }

    private function guestCheckout(ProductVariant $variant, int $qty, ?string $idempotencyKey = null, ?string $couponCode = null, string $phone = '01012345678'): TestResponse
    {
        $token = $this->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id, 'qty' => $qty,
        ])->assertOk()->json('data.cart_token');
        if ($couponCode !== null) {
            $this->withHeaders(['X-Cart-Session' => $token])
                ->postJson('/api/v1/store/cart/coupon', ['code' => $couponCode])->assertOk();
        }
        $headers = ['X-Cart-Session' => $token];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $this->withHeaders($headers)->postJson('/api/v1/store/checkout', [
            'recipient_name' => 'ضيف', 'recipient_phone' => $phone,
            'city_id' => City::factory()->create(['shipping_cost' => 0])->id,
            'shipping_address_line' => 'عنوان', 'payment_method' => 'cod',
        ]);
    }

    /** طلب COD مشحون عبر بوليصة شركة لها سر ويب هوك معروف. */
    private function shippedCodOrder(float $price = 100): array
    {
        $variant = $this->variant($price);
        $this->giveStock($variant, 10);
        $order = Order::findOrFail($this->guestCheckout($variant, 2)->assertCreated()->json('data.id'));
        $company = ShippingCompany::query()->firstOrCreate(
            ['code' => 'SHIPX'],
            ['name' => 'ShipX', 'is_active' => true, 'api_credentials' => ['webhook_secret' => 'ship-secret']],
        );
        $this->withToken($this->staffToken())->patchJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => 'confirmed',
        ])->assertOk();
        $this->withToken($this->staffToken())->postJson("/api/v1/admin/orders/{$order->id}/shipment", [
            'shipping_company_id' => $company->id,
        ])->assertOk();
        $order->refresh();
        $this->assertSame(OrderStatus::Shipped, $order->status);

        return [$order, $order->shipments()->firstOrFail(), $company];
    }

    private function shippingWebhook(Shipment $shipment, string $status, string $eventId): void
    {
        $body = json_encode([
            'event_id' => $eventId, 'awb_number' => $shipment->awb_number, 'status' => $status,
        ]);
        $this->call('POST', '/api/v1/webhooks/shipping/SHIPX', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $body, 'ship-secret')],
            $body)->assertOk();
    }

    // ---------- F-11 ----------

    public function test_customer_cancel_is_rejected_once_admin_starts_processing(): void
    {
        $variant = $this->variant();
        $this->giveStock($variant, 5);
        $customer = Customer::factory()->create(['phone_verified_at' => now()]);
        $token = $customer->createToken('t')->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id, 'qty' => 1,
        ])->assertOk();
        $orderId = $this->withToken($token)->postJson('/api/v1/store/checkout', [
            'recipient_name' => 'عميل', 'recipient_phone' => '01099999999',
            'city_id' => City::factory()->create(['shipping_cost' => 0])->id,
            'shipping_address_line' => 'عنوان', 'payment_method' => 'cod',
        ])->assertCreated()->json('data.id');

        $orders = app(OrderService::class);
        $order = Order::findOrFail($orderId);
        $orders->changeStatus($order, OrderStatus::Confirmed, $this->staff);
        $orders->changeStatus($order, OrderStatus::Processing, $this->staff);

        // العميل يلغي الآن — الطلب دخل التجهيز فعليًا فيُرفض بالفحص المقفول
        $this->withToken($token)->postJson("/api/v1/store/account/orders/{$orderId}/cancel")
            ->assertStatus(422);
        $this->assertSame(OrderStatus::Processing, Order::findOrFail($orderId)->status);
    }

    // ---------- F-13 ----------

    public function test_checkout_retry_with_same_idempotency_key_returns_same_order(): void
    {
        $variant = $this->variant();
        $this->giveStock($variant, 5);

        $first = $this->guestCheckout($variant, 1, 'checkout-key-abc')->assertCreated();
        $second = $this->guestCheckout($variant, 1, 'checkout-key-abc');

        // المحاولة الثانية لا تصطدم بـ«سلة فارغة» في createFromCart لأن
        // المفتاح يرجع الطلب القائم قبل لمس السلة (السلة الثانية جديدة
        // هنا بتوكن مختلف — والمفتاح هو الهوية)
        $second->assertCreated();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Order::query()->where('idempotency_key', 'checkout-key-abc')->count());
    }

    // ---------- F-14 ----------

    public function test_manual_delivered_settles_cod_and_paid_return_flags_refund(): void
    {
        // (أ) تسليم يدوي من الأدمن يسوّي COD
        [$order] = $this->shippedCodOrder();
        $this->assertSame('unpaid', $order->payment_status);
        $this->withToken($this->staffToken())->patchJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => 'delivered',
        ])->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);

        // (ب) مرتجع لطلب مدفوع (بوليصة + استلام مستودع) يرفع requires_refund
        [$paidOrder] = $this->shippedCodOrder();
        $paidOrder->update(['payment_status' => 'paid']);
        $this->withToken($this->staffToken())->patchJson("/api/v1/admin/orders/{$paidOrder->id}/status", [
            'status' => 'returned',
        ])->assertOk();
        $this->assertTrue($paidOrder->fresh()->requires_refund);
    }

    // ---------- F-16 ----------

    public function test_failed_shipment_flags_order_and_allows_replacement_waybill(): void
    {
        [$order, $shipment, $company] = $this->shippedCodOrder();
        $level = StockLevel::where('product_variant_id', $order->items->first()->product_variant_id)->first();
        $onHandAfterShip = (float) $level->on_hand;

        $this->shippingWebhook($shipment, 'failed', 'evt-fail-1');
        $order->refresh();
        $this->assertTrue($order->needs_attention);
        $this->assertSame(OrderStatus::Shipped, $order->status);

        // بوليصة بديلة تُصدر بلا خطأ حالة وبلا خصم مخزون ثانٍ
        $this->withToken($this->staffToken())->postJson("/api/v1/admin/orders/{$order->id}/shipment", [
            'shipping_company_id' => $company->id,
        ])->assertOk();
        $this->assertSame(2, $order->shipments()->count());
        $this->assertSame(OrderStatus::Shipped, $order->fresh()->status);
        $this->assertSame($onHandAfterShip, (float) $level->fresh()->on_hand);
    }

    // ---------- F-17 ----------

    public function test_guest_coupon_limited_per_phone_not_globally(): void
    {
        // (H3) أُزيل السقف العالمي الذي كان مهاجم واحد يُعطّل به الحملة على كل
        // الزوار. الحماية الآن لكل هوية: نفس الرقم لا يعيد استخدام الكوبون،
        // لكن رقم جديد لا يُحجب عالميًا.
        $variant = $this->variant();
        $this->giveStock($variant, 50);
        Coupon::create([
            'code' => 'CAMP', 'type' => 'fixed', 'value' => 10, 'is_active' => true,
            'usage_limit' => 100, 'usage_limit_per_customer' => 1,
        ]);

        $this->guestCheckout($variant, 1, null, 'CAMP', '01000000001')->assertCreated();
        // نفس الرقم: حد الاستخدام لكل هوية يمنع الإعادة
        $this->guestCheckout($variant, 1, null, 'CAMP', '01000000001')->assertStatus(422);
        // رقم مختلف: لا سقف عالمي يحجب الزوار الشرعيين
        $this->guestCheckout($variant, 1, null, 'CAMP', '01000000002')->assertCreated();
        $this->assertSame(2, Order::query()->count());
    }
}
