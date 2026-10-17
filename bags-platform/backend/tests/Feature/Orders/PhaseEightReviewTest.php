<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\City;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\ShippingCompany;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\WebhookLog;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\ShipmentService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * جولة مراجعة المرحلة 8: أقفال الصفوف (C1)، منع البوليصة المكررة (H1)،
 * سباق السلة (H2)، حدود الكوبون وهوية الزائر (H3)، ذرّية الويب هوك (H4)،
 * تحقق المبلغ وحماية paid (H5)، ترتيب حالات الشحنة (H6)، إزالة الكوبون (H8)،
 * وطرق الدفع المضبوطة (H9).
 */
class PhaseEightReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const PAY_SECRET = 'pay-secret';

    private const SHIP_SECRET = 'ship-secret';

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        config(['webhooks.payment.testpay' => [
            'secret' => self::PAY_SECRET,
            'signature_header' => 'X-Webhook-Signature',
        ]]);
        foreach (['orders.view', 'orders.update', 'orders.allocate'] as $permission) {
            Permission::findOrCreate($permission, 'staff');
        }
        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(['orders.view', 'orders.update', 'orders.allocate']);
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(Role::findOrCreate('Super Admin', 'staff'));
        $this->branch = Branch::factory()->create();
    }

    // ---------- C1 ----------

    public function test_change_status_uses_fresh_locked_state_not_the_stale_model(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 10);
        $order = $this->makeOrder($variant, 2, 'cod');
        $service = app(OrderService::class);
        $service->changeStatus($order, OrderStatus::Confirmed, $this->admin);

        // نسخة قديمة بيد "أدمن آخر" ما زالت ترى Confirmed — بينما الطلب أُلغي فعلًا
        $staleCopy = Order::findOrFail($order->id);
        $service->changeStatus($order->fresh(), OrderStatus::Cancelled, $this->admin);
        $this->assertSame('0.0000', (string) $this->level($variant->id)->reserved);

        // من النسخة القديمة يبدو الانتقال Confirmed→Shipped قانونيًا، لكن الحالة
        // الطازجة Cancelled فيجب أن يُرفض وألا يُفك الحجز مرة ثانية
        try {
            $service->changeStatus($staleCopy, OrderStatus::Shipped, $this->admin);
            $this->fail('انتقال من حالة ملغاة كان يجب أن يُرفض');
        } catch (BusinessException) {
            $this->assertSame('0.0000', (string) $this->level($variant->id)->reserved);
            $this->assertSame('10.0000', (string) $this->level($variant->id)->on_hand);
        }
    }

    // ---------- H1 ----------

    public function test_second_shipment_for_same_order_is_rejected(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 5);
        $order = $this->makeOrder($variant, 1, 'cod');
        app(OrderService::class)->changeStatus($order, OrderStatus::Confirmed, $this->admin);
        $company = ShippingCompany::factory()->create();

        // إعادة إنشاء على الطلب المشحون الآن: الحالة وحدها لا تكفي (no-op في آلة
        // الحالات) — حارس البوليصة النشطة هو ما يمنع التكرار
        $shippedOrder = $this->shipOrder($order, $company);
        try {
            app(ShipmentService::class)->createForOrder($shippedOrder, $company->id, null, $this->admin);
            $this->fail('بوليصة ثانية كان يجب أن تُرفض');
        } catch (BusinessException) {
            $this->assertSame(1, Shipment::query()->count());
        }
    }

    // ---------- H2 ----------

    public function test_second_checkout_on_same_cart_fails_empty_after_first_consumes_it(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 10);
        $cart = Cart::create([]);
        $cart->items()->create(['product_variant_id' => $variant->id, 'qty' => 1]);
        $data = ['recipient_name' => 'عميل', 'recipient_phone' => '01012345678', 'payment_method' => 'cod'];

        app(OrderService::class)->createFromCart($cart, $data);
        $this->assertSame(1, Order::query()->count());

        try {
            // نفس نسخة السلة المحملة من الطلب الأول (نقرة مكررة)
            app(OrderService::class)->createFromCart($cart, $data);
            $this->fail('شراء ثانٍ من نفس السلة كان يجب أن يفشل');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('فارغة', $e->getMessage());
            $this->assertSame(1, Order::query()->count());
        }
    }

    // ---------- H3 ----------

    public function test_guest_cannot_exceed_per_customer_coupon_limit_by_changing_cart(): void
    {
        Coupon::factory()->create(['code' => 'ONCE', 'type' => 'fixed', 'value' => 10, 'usage_limit_per_customer' => 1]);
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 10);
        $coupon = Coupon::query()->sole();

        $buyAsGuest = function (string $phone) use ($variant, $coupon): void {
            $cart = Cart::create(['coupon_id' => $coupon->id]);
            $cart->items()->create(['product_variant_id' => $variant->id, 'qty' => 1]);
            app(OrderService::class)->createFromCart($cart, [
                'recipient_name' => 'زائر', 'recipient_phone' => $phone, 'payment_method' => 'cod',
            ]);
        };

        $buyAsGuest('01012345678');
        $this->assertSame(1, Order::query()->count());

        // نفس الرقم بصيغة دولية وسلة جديدة تمامًا (cart_token مختلف)
        try {
            $buyAsGuest('+201012345678');
            $this->fail('تجاوز حد الكوبون برقم الهاتف نفسه كان يجب أن يُرفض');
        } catch (BusinessException $e) {
            $this->assertSame('coupon_invalid', $e->errorCode); // H8: رمز مميز
            $this->assertSame(1, Order::query()->count());
            $this->assertSame(1, Coupon::query()->sole()->used_count);
        }
    }

    // ---------- H5 ----------

    public function test_payment_webhook_rejects_amount_mismatch(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 5);
        $order = $this->makeOrder($variant, 1, 'card'); // grand_total = 100

        $this->postWebhook('/api/v1/webhooks/payment/testpay', [
            'event_id' => 'evt_amt', 'order_number' => $order->order_number,
            'status' => 'paid', 'amount' => 1, 'currency' => 'EGP',
        ], self::PAY_SECRET)->assertOk()->assertJsonPath('received', false);

        $order = $order->fresh();
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame('amount mismatch', WebhookLog::query()->sole()->error);
    }

    public function test_payment_webhook_pending_is_not_failure_and_late_failed_does_not_override_paid(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 5);
        $order = $this->makeOrder($variant, 1, 'card');

        // معاملة معلقة: success=false + pending=true — ليست فشلًا
        $this->postWebhook('/api/v1/webhooks/payment/testpay', [
            'event_id' => 'evt_pend', 'order_number' => $order->order_number,
            'obj' => ['success' => false, 'pending' => true],
        ], self::PAY_SECRET)->assertOk();
        $this->assertSame('unpaid', $order->fresh()->payment_status);

        // نجاح ثم فشل متأخر بترتيب خاطئ
        $this->postWebhook('/api/v1/webhooks/payment/testpay', [
            'event_id' => 'evt_ok', 'order_number' => $order->order_number, 'status' => 'paid',
        ], self::PAY_SECRET)->assertOk();
        $this->postWebhook('/api/v1/webhooks/payment/testpay', [
            'event_id' => 'evt_late_fail', 'order_number' => $order->order_number, 'status' => 'failed',
        ], self::PAY_SECRET)->assertOk();

        $order = $order->fresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(OrderStatus::Confirmed, $order->status);
    }

    public function test_payment_on_cancelled_order_marks_requires_refund(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 5);
        $order = $this->makeOrder($variant, 1, 'card');
        app(OrderService::class)->changeStatus($order, OrderStatus::Cancelled, $this->admin);

        $this->postWebhook('/api/v1/webhooks/payment/testpay', [
            'event_id' => 'evt_ref', 'order_number' => $order->order_number, 'status' => 'paid',
        ], self::PAY_SECRET)->assertOk();

        $order = $order->fresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertTrue((bool) $order->requires_refund);
        $this->assertSame(OrderStatus::Cancelled, $order->status);
    }

    // ---------- H6 ----------

    public function test_shipping_webhook_ignores_regression_and_terminal_states_are_final(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 5);
        $order = $this->makeOrder($variant, 1, 'cod');
        app(OrderService::class)->changeStatus($order, OrderStatus::Confirmed, $this->admin);
        $company = ShippingCompany::factory()->create([
            'code' => 'bosta',
            'api_credentials' => ['webhook_secret' => self::SHIP_SECRET],
        ]);
        $this->shipOrder($order, $company, 'BOSTA-9');

        // كل الأحداث تحمل id الشحنة نفسه الثابت — لا يجب أن تُبتلع كتكرار
        $send = fn (string $status) => $this->postWebhook('/api/v1/webhooks/shipping/bosta', [
            'id' => 'shipment-fixed-id', 'awb_number' => 'BOSTA-9', 'status' => $status,
        ], self::SHIP_SECRET)->assertOk();

        $send('delivered');
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);

        // حدث متأخر in_transit بعد التسليم: يُتجاهل ولا يرجع بالحالة
        $send('in_transit');
        $shipment = Shipment::query()->sole();
        $this->assertSame('delivered', $shipment->status->value);
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }

    // ---------- H8 ----------

    public function test_customer_can_remove_coupon_from_cart(): void
    {
        Coupon::factory()->create(['code' => 'TEN', 'type' => 'fixed', 'value' => 10]);
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 5);
        $token = $this->getJson('/api/v1/store/cart/items')->json('data.cart_token');
        $headers = ['X-Cart-Session' => $token];

        $this->withHeaders($headers)->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id, 'qty' => 1,
        ])->assertOk();
        $this->withHeaders($headers)->postJson('/api/v1/store/cart/coupon', ['code' => 'TEN'])
            ->assertOk()->assertJsonPath('data.cart.totals.coupon_code', 'TEN')
            ->assertJsonPath('data.coupon_discount', 10);

        $this->withHeaders($headers)->deleteJson('/api/v1/store/cart/coupon')
            ->assertOk()->assertJsonPath('data.totals.coupon_code', null)
            ->assertJsonPath('data.totals.coupon_discount', 0);
    }

    // ---------- H9 ----------

    public function test_store_checkout_rejects_unconfigured_payment_methods_and_guest_credit(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 5);
        $city = City::factory()->create(['shipping_cost' => 0]);
        $token = $this->getJson('/api/v1/store/cart/items')->json('data.cart_token');
        $headers = ['X-Cart-Session' => $token];
        $this->withHeaders($headers)->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id, 'qty' => 1,
        ])->assertOk();

        foreach (['card', 'credit', 'wallet'] as $method) {
            $this->withHeaders($headers)->postJson('/api/v1/store/checkout', [
                'recipient_name' => 'زائر', 'recipient_phone' => '01012345678',
                'city_id' => $city->id, 'shipping_address_line' => 'عنوان',
                'payment_method' => $method,
            ])->assertUnprocessable();
        }
        $this->assertSame(0, Order::query()->count());

        // cod المضبوط افتراضيًا يعمل
        $this->withHeaders($headers)->postJson('/api/v1/store/checkout', [
            'recipient_name' => 'زائر', 'recipient_phone' => '01012345678',
            'city_id' => $city->id, 'shipping_address_line' => 'عنوان', 'payment_method' => 'cod',
        ])->assertCreated();
    }

    // ---------- helpers ----------

    private function shipOrder(Order $order, ShippingCompany $company, ?string $awb = null): Order
    {
        app(ShipmentService::class)->createForOrder($order, $company->id, $awb, $this->admin);

        return $order->fresh();
    }

    private function postWebhook(string $uri, array $payload, string $secret): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', $uri, [], [], [], [
            'HTTP_X-Webhook-Signature' => hash_hmac('sha256', $body, $secret),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    private function makeOrder(ProductVariant $variant, float|int $qty, string $paymentMethod): Order
    {
        $cart = Cart::create([]);
        $cart->items()->create(['product_variant_id' => $variant->id, 'qty' => $qty]);

        return app(OrderService::class)->createFromCart($cart, [
            'recipient_name' => 'عميل', 'recipient_phone' => '01012345678',
            'payment_method' => $paymentMethod,
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
}
