<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\StockMovementType as MovementType;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\City;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingCompany;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\OrderAllocationService;
use App\Services\OrderService;
use Database\Seeders\OrderPermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\ShippingPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * انحدار F-01..F-10: الحجز عند الشراء وانتهاء المعلقة، كميات صحيحة،
 * حد كوبون الزائر في SQL، الاسترداد يوقف الشحن، أنماط توقيع البوابات،
 * حقول غير موقعة آمنة، مفتاح حدث بالنتيجة، وUpsert السلة الذرّي.
 */
class CheckoutReservationReviewTest extends TestCase
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
        $this->branch = Branch::factory()->create(['name' => 'الفرع الرئيسي', 'city' => 'القاهرة']);
        $this->branch->locations()->firstOrCreate(['code' => 'S-01'], ['name' => 'رف', 'type' => 'shelf']);
    }

    private function makeVariant(float $price = 100): ProductVariant
    {
        $product = Product::factory()->create(['status' => 'published']);

        return $product->variants()->create([
            'name_ar' => 'قياسي', 'sku' => 'SKU-'.uniqid(), 'retail_price' => $price, 'is_active' => true,
        ]);
    }

    private function giveStock(int $variantId, float $qty): void
    {
        $location = $this->branch->locations()->firstOrFail();
        StockLevel::updateOrCreate(
            ['branch_id' => $this->branch->id, 'location_id' => $location->id, 'product_variant_id' => $variantId],
            ['on_hand' => $qty],
        );
    }

    // ---------- F-01: الحجز عند الشراء ----------

    public function test_checkout_reserves_stock_immediately_and_blocks_oversell(): void
    {
        $variant = $this->makeVariant();
        $this->giveStock($variant->id, 2);
        $city = City::factory()->create(['shipping_cost' => 0]);

        $checkout = fn (): TestResponse => $this->withHeaders(['X-Cart-Session' => (string) Str::uuid()])
            ->postJson('/api/v1/store/cart/items', ['product_variant_id' => $variant->id, 'qty' => 2]);

        // العميل الأول يضيف آخر وحدتين ويشتري
        $first = $this->postJson('/api/v1/store/cart/items', ['product_variant_id' => $variant->id, 'qty' => 2]);
        $first->assertOk();
        $token = $first->json('data.cart_token');
        $order = $this->withHeaders(['X-Cart-Session' => $token])->postJson('/api/v1/store/checkout', [
            'recipient_name' => 'أحمد', 'recipient_phone' => '01000000001', 'shipping_address_line' => 'عنوان',
            'city_id' => $city->id, 'payment_method' => 'cod',
        ])->assertCreated()->json('data');

        // الحجز موجود من لحظة الإنشاء والمتاح صفر
        $level = StockLevel::where('product_variant_id', $variant->id)->first();
        $this->assertEquals(2, (float) $level->reserved);
        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => 'order', 'reference_id' => $order['id'], 'type' => MovementType::Reserve->value,
        ]);

        // عميل ثانٍ لنفس آخر وحدة: الإضافة نفسها تُرفض لنفاد المتاح
        $checkout()->assertStatus(422);
    }

    public function test_cancelling_a_pending_order_releases_its_reservation(): void
    {
        $variant = $this->makeVariant();
        $this->giveStock($variant->id, 3);
        $customer = Customer::factory()->create(['phone_verified_at' => now()]);
        $cart = Cart::factory()->for($customer)->create();
        $cart->items()->create(['product_variant_id' => $variant->id, 'qty' => 2]);
        City::factory()->create();

        $order = app(OrderService::class)->createFromCart($cart->fresh(), [
            'payment_method' => 'cod', 'shipping_cost' => 0, 'city_id' => null,
            'address_snapshot' => ['city' => 'القاهرة'],
        ], $customer);
        $this->assertEquals(2, (float) StockLevel::where('product_variant_id', $variant->id)->first()->reserved);

        $this->withToken($customer->createToken('t')->plainTextToken)
            ->postJson("/api/v1/store/account/orders/{$order->id}/cancel")->assertOk();

        $this->assertEquals(0, (float) StockLevel::where('product_variant_id', $variant->id)->first()->reserved);
    }

    public function test_expire_pending_command_cancels_stale_orders_and_releases_stock(): void
    {
        $variant = $this->makeVariant();
        $this->giveStock($variant->id, 5);

        $makeOrder = function (string $method, int $ageHours) use ($variant): Order {
            $customer = Customer::factory()->create();
            $cart = Cart::factory()->for($customer)->create();
            $cart->items()->create(['product_variant_id' => $variant->id, 'qty' => 1]);
            $order = app(OrderService::class)->createFromCart($cart->fresh(), [
                'payment_method' => $method, 'shipping_cost' => 0, 'city_id' => null,
                'address_snapshot' => ['city' => 'القاهرة'],
            ], $customer);
            $order->forceFill(['created_at' => now()->subHours($ageHours)])->saveQuietly();

            return $order;
        };

        $staleCod = $makeOrder('cod', 100);       // أقدم من 72 ساعة
        $staleOnline = $makeOrder('card', 2);     // إلكتروني غير مدفوع أقدم من 30 دقيقة
        $freshCod = $makeOrder('cod', 1);         // حديث — يبقى

        Artisan::call('orders:expire-pending');

        $this->assertSame('cancelled', $staleCod->fresh()->status->value);
        $this->assertSame('cancelled', $staleOnline->fresh()->status->value);
        $this->assertSame('pending', $freshCod->fresh()->status->value);
        // حجوزات الملغاة رُفعت ويبقى حجز الطلب الحديث فقط
        $this->assertEquals(1, (float) StockLevel::where('product_variant_id', $variant->id)->first()->reserved);
    }

    // ---------- F-03: كميات صحيحة ----------

    public function test_fractional_quantities_are_rejected_on_cart_endpoints(): void
    {
        $variant = $this->makeVariant();
        $this->giveStock($variant->id, 10);

        $this->postJson('/api/v1/store/cart/items', ['product_variant_id' => $variant->id, 'qty' => 2.5])
            ->assertStatus(422);
        $this->postJson('/api/v1/store/cart/items', ['product_variant_id' => $variant->id, 'qty' => 0.0001])
            ->assertStatus(422);
        $this->postJson('/api/v1/store/cart/items', ['product_variant_id' => $variant->id, 'qty' => 2])
            ->assertOk();
    }

    // ---------- F-04: حد كوبون الزائر من العمود الموحد ----------

    public function test_guest_coupon_limit_uses_the_stored_normalized_phone(): void
    {
        $variant = $this->makeVariant();
        $this->giveStock($variant->id, 10);
        $city = City::factory()->create(['shipping_cost' => 0]);
        $coupon = Coupon::create([
            'code' => 'ONCE', 'type' => 'fixed', 'value' => 10, 'is_active' => true,
            'usage_limit' => null, 'usage_limit_per_customer' => 1,
        ]);

        $buy = function (string $phone) use ($variant, $city, $coupon): TestResponse {
            $add = $this->postJson('/api/v1/store/cart/items', ['product_variant_id' => $variant->id, 'qty' => 1]);
            $token = $add->json('data.cart_token');
            $this->withHeaders(['X-Cart-Session' => $token])
                ->postJson('/api/v1/store/cart/coupon', ['code' => $coupon->code])->assertOk();

            return $this->withHeaders(['X-Cart-Session' => $token])->postJson('/api/v1/store/checkout', [
                'recipient_name' => 'زائر', 'recipient_phone' => $phone, 'shipping_address_line' => 'عنوان',
                'city_id' => $city->id, 'payment_method' => 'cod',
            ]);
        };

        $buy('0100 123 4567')->assertCreated();
        // الاستخدام مخزّن بالهاتف الموحد على سجل الاستخدام نفسه
        $this->assertDatabaseHas('coupon_usages', [
            'coupon_id' => $coupon->id, 'guest_phone_normalized' => Customer::normalizePhone('0100 123 4567'),
        ]);
        // نفس الرقم بصيغة دولية: الحد (1) يمنع الشراء الثاني
        $buy('+201001234567')->assertStatus(422);
    }

    // ---------- F-05: الاسترداد يوقف الشحن ----------

    public function test_prepaid_order_cannot_ship_unpaid_or_refunded(): void
    {
        $company = ShippingCompany::factory()->create(['code' => 'BOSTA', 'is_active' => true]);
        $variant = $this->makeVariant();
        $this->giveStock($variant->id, 5);

        $makeOrder = function (string $paymentStatus) use ($variant): Order {
            $order = Order::factory()->create([
                'status' => 'confirmed', 'payment_method' => 'card', 'payment_status' => $paymentStatus,
                'grand_total' => 100, 'city_id' => null,
            ]);
            $order->items()->create([
                'product_variant_id' => $variant->id, 'product_name_snapshot' => 'P',
                'sku_snapshot' => 'SKU', 'qty' => 1, 'unit_price' => 100, 'line_total' => 100,
            ]);
            app(OrderAllocationService::class)->allocate($order->fresh(), performedBy: $this->staff->id);

            return $order;
        };

        $staffToken = $this->staff->createToken('t')->plainTextToken;
        $ship = fn (Order $order) => $this->withToken($staffToken)
            ->postJson("/api/v1/admin/orders/{$order->id}/shipment", ['shipping_company_id' => $company->id]);

        $ship($makeOrder('unpaid'))->assertStatus(422);
        $ship($makeOrder('refunded'))->assertStatus(422);
        $ship($makeOrder('paid'))->assertOk();
    }

    public function test_refund_webhook_flags_the_order_for_attention(): void
    {
        Config::set('webhooks.payment.testpay', ['secret' => 'refund-secret', 'signature_header' => 'X-Webhook-Signature']);
        $order = Order::factory()->create(['status' => 'confirmed', 'payment_status' => 'paid', 'grand_total' => 100]);
        $body = json_encode(['transaction_id' => 'tx-r1', 'status' => 'refunded', 'order_number' => $order->order_number]);

        $this->call('POST', '/api/v1/webhooks/payment/testpay', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $body, 'refund-secret')],
            $body)->assertOk();

        $order->refresh();
        $this->assertSame('refunded', $order->payment_status);
        $this->assertTrue($order->needs_attention);
    }

    // ---------- F-06 + F-08: أنماط البوابات ومفتاح الحدث ----------

    public function test_stripe_signature_scheme_with_replay_protection(): void
    {
        Config::set('webhooks.payment.stripe', ['driver' => 'stripe', 'secret' => 'whsec_test']);
        $order = Order::factory()->create(['status' => 'pending', 'payment_status' => 'unpaid', 'grand_total' => 100]);
        $body = json_encode(['id' => 'evt_9', 'status' => 'paid', 'order_number' => $order->order_number, 'amount' => 100]);
        $sign = fn (int $t): string => 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, 'whsec_test');

        $this->call('POST', '/api/v1/webhooks/payment/stripe', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $sign(time())], $body)
            ->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);

        // طابع قديم (إعادة إرسال) يُرفض رغم صحة التوقيع حسابيًا
        $this->call('POST', '/api/v1/webhooks/payment/stripe', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $sign(time() - 10000)], $body)
            ->assertStatus(403);
    }

    public function test_paymob_hmac_scheme_and_refund_not_merged_with_payment(): void
    {
        Config::set('webhooks.payment.paymob', ['driver' => 'paymob', 'secret' => 'paymob_hmac']);
        $order = Order::factory()->create(['status' => 'pending', 'payment_status' => 'unpaid', 'grand_total' => 250]);
        $obj = [
            'amount_cents' => 25000, 'created_at' => '2026-10-18T00:00:00', 'currency' => 'EGP',
            'error_occured' => false, 'has_parent_transaction' => false, 'id' => 777,
            'integration_id' => 123, 'is_3d_secure' => false, 'is_auth' => false,
            'is_capture' => false, 'is_refunded' => false, 'is_standalone_payment' => true,
            'is_voided' => false,
            'order' => ['id' => 55, 'merchant_order_id' => $order->order_number],
            'owner' => 1, 'pending' => false,
            'source_data' => ['pan' => 'xxxx', 'sub_type' => 'MasterCard', 'type' => 'card'],
            'success' => true,
        ];
        $concat = '250002026-10-18T00:00:00EGPfalsefalse777123falsefalsefalsefalsetruefalse55'.
            '1falsexxxxMasterCardcardtrue';
        $hmac = hash_hmac('sha512', $concat, 'paymob_hmac');
        $body = json_encode(['obj' => $obj]);

        $this->call('POST', '/api/v1/webhooks/payment/paymob?hmac='.$hmac, [], [], [],
            ['CONTENT_TYPE' => 'application/json'], $body)->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);

        // نفس معرّف المعاملة (777) لكن الحدث استرداد: لا يُبتلع كـ duplicate (F-08)
        $body2 = json_encode(['transaction_id' => 777, 'status' => 'refunded', 'order_number' => $order->order_number]);
        Config::set('webhooks.payment.testpay', ['secret' => 's8', 'signature_header' => 'X-Webhook-Signature']);
        $this->call('POST', '/api/v1/webhooks/payment/testpay', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $body2, 's8')],
            $body2)->assertOk();
        $this->assertSame('refunded', $order->fresh()->payment_status);
    }

    public function test_moyasar_secret_token_scheme(): void
    {
        Config::set('webhooks.payment.moyasar', ['driver' => 'moyasar', 'secret' => 'ms_token']);
        $order = Order::factory()->create(['status' => 'pending', 'payment_status' => 'unpaid', 'grand_total' => 100]);
        $good = json_encode(['id' => 'pay_1', 'secret_token' => 'ms_token', 'status' => 'paid', 'order_number' => $order->order_number, 'amount' => 100]);
        $bad = json_encode(['id' => 'pay_2', 'secret_token' => 'wrong', 'status' => 'paid', 'order_number' => $order->order_number, 'amount' => 100]);

        $this->call('POST', '/api/v1/webhooks/payment/moyasar', [], [], [],
            ['CONTENT_TYPE' => 'application/json'], $bad)->assertStatus(403);
        $this->call('POST', '/api/v1/webhooks/payment/moyasar', [], [], [],
            ['CONTENT_TYPE' => 'application/json'], $good)->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    // ---------- F-07: حقول غير موقعة لا تكسر المعالجة ----------

    public function test_malformed_event_fields_do_not_produce_500(): void
    {
        Config::set('webhooks.payment.testpay', ['secret' => 's7', 'signature_header' => 'X-Webhook-Signature']);
        // event_id مصفوفة + رقم طلب كمصفوفة: توقيع صحيح، فلا 500
        $body = json_encode(['transaction_id' => ['nested' => 'array'], 'status' => 'paid', 'order_number' => ['x']]);
        $response = $this->call('POST', '/api/v1/webhooks/payment/testpay', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $body, 's7')],
            $body);
        $this->assertNotSame(500, $response->status());

        // بوابة باسم طويل جدًا: قيد المسار يرد 404 لا 500 بقاعدة البيانات
        $this->call('POST', '/api/v1/webhooks/payment/'.str_repeat('a', 60), [], [], [],
            ['CONTENT_TYPE' => 'application/json'], '{}')->assertNotFound();
    }

    // ---------- F-09 وF-02: سلوك السلة والتوزيع ----------

    public function test_cart_add_and_merge_accumulate_via_upsert(): void
    {
        $variant = $this->makeVariant();
        $this->giveStock($variant->id, 20);

        $add = $this->postJson('/api/v1/store/cart/items', ['product_variant_id' => $variant->id, 'qty' => 2]);
        $token = $add->json('data.cart_token');
        $this->withHeaders(['X-Cart-Session' => $token])
            ->postJson('/api/v1/store/cart/items', ['product_variant_id' => $variant->id, 'qty' => 3])
            ->assertOk();
        $this->assertDatabaseHas('cart_items', ['product_variant_id' => $variant->id, 'qty' => 5]);
        $this->assertSame(1, CartItem::where('product_variant_id', $variant->id)->count());
    }

    public function test_allocation_reserves_items_regardless_of_line_order(): void
    {
        $v1 = $this->makeVariant();
        $v2 = $this->makeVariant();
        $this->giveStock($v1->id, 5);
        $this->giveStock($v2->id, 5);
        $order = Order::factory()->create(['status' => 'pending']);
        // بنود بترتيب معكوس لمعرّفات المتغيرات — مسار القفل المرتب يعمل (F-02)
        $order->items()->create(['product_variant_id' => $v2->id, 'product_name_snapshot' => 'B', 'sku_snapshot' => 'B', 'qty' => 2, 'unit_price' => 10, 'line_total' => 20]);
        $order->items()->create(['product_variant_id' => $v1->id, 'product_name_snapshot' => 'A', 'sku_snapshot' => 'A', 'qty' => 3, 'unit_price' => 10, 'line_total' => 30]);

        app(OrderAllocationService::class)->allocate($order->fresh(), performedBy: $this->staff->id);

        $this->assertEquals(3, (float) StockLevel::where('product_variant_id', $v1->id)->first()->reserved);
        $this->assertEquals(2, (float) StockLevel::where('product_variant_id', $v2->id)->first()->reserved);
    }
}
