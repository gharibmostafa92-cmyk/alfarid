<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\City;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\Shipment;
use App\Models\ShippingCompany;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\WebhookLog;
use App\Services\CartService;
use App\Services\OrderService;
use App\Services\ShipmentService;
use Database\Seeders\PromotionPermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\ShippingPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * اختبارات انحدار لجولة المراجعة الثانية للمرحلة 8 (M1–M10).
 */
class StoreHardeningReviewTest extends TestCase
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

    private function giveStock(ProductVariant $variant, float $qty): void
    {
        $location = Location::factory()->create(['branch_id' => $this->branch->id]);
        StockLevel::query()->forceCreate([
            'branch_id' => $this->branch->id, 'location_id' => $location->id,
            'product_variant_id' => $variant->id, 'on_hand' => $qty, 'reserved' => 0,
        ]);
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function guestCartWithItem(ProductVariant $variant): array
    {
        $token = $this->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id, 'qty' => 1,
        ])->assertOk()->json('data.cart_token');

        return [$token, ['X-Cart-Session' => $token]];
    }

    // ---------- M1 ----------

    public function test_long_arabic_address_and_status_notes_fit(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 5);
        $city = City::factory()->create(['shipping_cost' => 0]);
        [$token, $headers] = $this->guestCartWithItem($variant);

        $long = str_repeat('ش', 400); // 400 حرف — كان ينهار 22001
        $orderId = $this->withHeaders($headers)->postJson('/api/v1/store/checkout', [
            'recipient_name' => 'ضيف', 'recipient_phone' => '01012345678',
            'city_id' => $city->id, 'shipping_address_line' => $long,
            'shipping_notes' => $long, 'payment_method' => 'cod',
        ])->assertCreated()->json('data.id');

        $this->withToken($this->staff->createToken('t')->plainTextToken)
            ->patchJson("/api/v1/admin/orders/{$orderId}/status", [
                'status' => 'confirmed', 'notes' => str_repeat('n', 300),
            ])->assertOk();
        $this->assertSame(300, mb_strlen((string) Order::find($orderId)->statusHistories()->latest('id')->first()->notes));
    }

    // ---------- M2 + M3 ----------

    public function test_garbage_cart_token_is_ignored_and_reads_do_not_persist(): void
    {
        // قراءة بلا توكن لا تنشئ أي صف
        $this->getJson('/api/v1/store/cart/items')->assertOk();
        $this->getJson('/api/v1/store/cart/items')->assertOk();
        $this->assertSame(0, Cart::count());

        // توكن ليس UUID: يُتجاهل ولا ينهار بـ 22P02، والسلة الجديدة بتوكن خادمي
        $response = $this->withHeaders(['X-Cart-Session' => 'not-a-uuid!!'])
            ->getJson('/api/v1/store/cart/items')->assertOk();
        $this->assertSame(0, Cart::count());
        $this->assertNotSame('not-a-uuid!!', $response->json('data.cart_token'));

        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 5);
        $added = $this->withHeaders(['X-Cart-Session' => 'not-a-uuid!!'])
            ->postJson('/api/v1/store/cart/items', ['product_variant_id' => $variant->id, 'qty' => 1])
            ->assertOk();
        $this->assertTrue(Str::isUuid((string) $added->json('data.cart_token')));
        $this->assertSame(1, Cart::count());
    }

    // ---------- M4 ----------

    public function test_invalid_signature_webhook_is_logged_slim_without_payload(): void
    {
        config(['webhooks.payment.testpay' => ['secret' => 'pay-secret', 'signature_header' => 'X-Webhook-Signature']]);

        $this->call('POST', '/api/v1/webhooks/payment/testpay', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_SIGNATURE' => 'bogus'],
            json_encode(['event_id' => 'evt-x', 'secret_data' => 'should-not-be-stored']))
            ->assertForbidden();

        $log = WebhookLog::query()->sole();
        $this->assertSame('invalid_signature', $log->status);
        $this->assertNull($log->payload);
    }

    public function test_oversized_webhook_body_is_rejected(): void
    {
        config(['webhooks.payment.testpay' => ['secret' => 'pay-secret', 'signature_header' => 'X-Webhook-Signature']]);
        $big = json_encode(['event_id' => 'evt-big', 'blob' => str_repeat('a', 300000)]);

        $this->call('POST', '/api/v1/webhooks/payment/testpay', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_SIGNATURE' => 'x'], $big)
            ->assertStatus(413);
        $this->assertNull(WebhookLog::query()->sole()->payload);
    }

    // ---------- M5 ----------

    public function test_tracking_hides_internal_notes_and_normalizes_phone(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 5);
        $city = City::factory()->create(['shipping_cost' => 0]);
        [$token, $headers] = $this->guestCartWithItem($variant);
        $number = $this->withHeaders($headers)->postJson('/api/v1/store/checkout', [
            'recipient_name' => 'ضيف', 'recipient_phone' => '01012345678',
            'city_id' => $city->id, 'shipping_address_line' => 'عنوان', 'payment_method' => 'cod',
        ])->assertCreated()->json('data.order_number');

        Order::where('order_number', $number)->first()
            ->statusHistories()->create([
                'from_status' => OrderStatus::Pending, 'to_status' => OrderStatus::Pending,
                'notes' => 'ملاحظة داخلية سرية',
            ]);

        $tracked = $this->getJson("/api/v1/store/orders/{$number}/track?phone=%2B201012345678")
            ->assertOk()->json('data');
        foreach ($tracked['status_history'] as $entry) {
            $this->assertArrayNotHasKey('notes', $entry);
        }
    }

    // ---------- M6 ----------

    public function test_double_merge_does_not_double_quantities(): void
    {
        $variant = $this->purchasableVariant(100);
        $customer = Customer::factory()->create();
        $guest = Cart::create([]);
        $guest->items()->create(['product_variant_id' => $variant->id, 'qty' => 2]);

        $carts = app(CartService::class);
        $carts->mergeGuestCart($customer, $guest->cart_token);
        $carts->mergeGuestCart($customer, $guest->cart_token); // طلب متوازٍ بنفس التوكن

        $account = Cart::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(1, Cart::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(2.0, (float) $account->items()->sole()->qty);
    }

    // ---------- M7 ----------

    public function test_non_numeric_route_params_return_404_not_500(): void
    {
        $this->putJson('/api/v1/store/cart/items/abc', ['qty' => 1])->assertNotFound();
        $this->withToken($this->staff->createToken('t')->plainTextToken)
            ->getJson('/api/v1/admin/coupons/abc')->assertNotFound();
        $this->withToken($this->staff->createToken('t')->plainTextToken)
            ->getJson('/api/v1/admin/orders/abc')->assertNotFound();
        $this->getJson('/api/v1/store/account/orders/abc')->assertNotFound();
    }

    // ---------- M8 ----------

    public function test_explicit_nulls_do_not_crash_coupon_and_promotion_saves(): void
    {
        $token = $this->staff->createToken('t')->plainTextToken;

        $couponId = $this->withToken($token)->postJson('/api/v1/admin/coupons', [
            'code' => 'NULLS', 'type' => 'percentage', 'value' => null,
            'usage_limit_per_customer' => null, 'is_active' => null,
        ])->assertCreated()->json('data.id');
        $this->assertSame(0.0, (float) Coupon::find($couponId)->value);

        $this->withToken($token)->patchJson("/api/v1/admin/coupons/{$couponId}", [
            'code' => 'NULLS', 'type' => 'percentage', 'value' => 25,
        ])->assertOk();
        $this->withToken($token)->patchJson("/api/v1/admin/coupons/{$couponId}", [
            'code' => 'NULLS', 'type' => 'percentage', 'value' => null, 'is_active' => null,
        ])->assertOk();
        $this->assertSame(25.0, (float) Coupon::find($couponId)->value);
        $this->assertTrue((bool) Coupon::find($couponId)->is_active);

        $promotionId = $this->withToken($token)->postJson('/api/v1/admin/promotions', [
            'name' => 'عرض', 'type' => 'percentage_discount', 'value' => null,
            'applies_to' => null, 'is_active' => null,
        ])->assertCreated()->json('data.id');
        $this->assertNotNull(Promotion::find($promotionId));
    }

    // ---------- M9 ----------

    public function test_delete_guards_for_companies_and_coupons(): void
    {
        $this->seed(ShippingPermissionSeeder::class);
        $token = $this->staff->createToken('t')->plainTextToken;

        // شركة لها بوليصة: حذفها 422
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 5);
        $order = Order::factory()->create();
        $order->items()->create([
            'product_variant_id' => $variant->id, 'product_name_snapshot' => 'x',
            'sku_snapshot' => 's', 'qty' => 1, 'unit_price' => 100, 'line_total' => 100,
        ]);
        app(OrderService::class)->changeStatus($order, OrderStatus::Confirmed, $this->staff);
        $company = ShippingCompany::factory()->create(['code' => 'DHLX']);
        app(ShipmentService::class)->createForOrder($order->fresh(), $company->id, null, $this->staff);
        $this->withToken($token)->deleteJson("/api/v1/admin/shipping-companies/{$company->id}")
            ->assertUnprocessable();
        $this->assertNotNull(ShippingCompany::find($company->id));

        // كوبون له استخدامات: 422 — وكوبون بلا استخدامات يُحذف وتُفك سلاله
        $used = Coupon::factory()->create(['code' => 'USED1']);
        CouponUsage::create(['coupon_id' => $used->id, 'customer_id' => null, 'order_id' => $order->id, 'discount_amount' => 5]);
        $this->withToken($token)->deleteJson("/api/v1/admin/coupons/{$used->id}")->assertUnprocessable();
        $this->assertNotNull(Coupon::find($used->id));
        $this->assertSame(1, CouponUsage::count());

        $unused = Coupon::factory()->create(['code' => 'FREE1']);
        $cart = Cart::create(['coupon_id' => $unused->id]);
        $this->withToken($token)->deleteJson("/api/v1/admin/coupons/{$unused->id}")->assertOk();
        $this->assertNull($cart->fresh()->coupon_id);
        $this->assertNull(Coupon::find($unused->id));
    }

    // ---------- M10 ----------

    public function test_null_api_credentials_update_keeps_secrets_and_shipping_webhook_needs_webhook_secret(): void
    {
        $this->seed(ShippingPermissionSeeder::class);
        $token = $this->staff->createToken('t')->plainTextToken;

        $company = ShippingCompany::factory()->create([
            'code' => 'KEYONLY', 'api_credentials' => ['api_key' => 'key-123'],
        ]);
        $this->withToken($token)->patchJson("/api/v1/admin/shipping-companies/{$company->id}", [
            'name' => $company->name, 'code' => $company->code, 'api_credentials' => null,
        ])->assertOk()->assertJsonPath('data.has_api_credentials', true);
        $this->assertSame(['api_key' => 'key-123'], $company->fresh()->api_credentials);

        // api_key وحده لا يصلح سر توقيع — حتى لو وقّعنا به
        $shipment = Shipment::factory()->create(['shipping_company_id' => $company->id, 'awb_number' => 'AWB-K1']);
        $body = json_encode(['awb' => 'AWB-K1', 'status' => 'in_transit']);
        $this->call('POST', '/api/v1/webhooks/shipping/KEYONLY', [], [], [],
            ['CONTENT_TYPE' => 'application/json',
                'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', (string) $body, 'key-123')], (string) $body)
            ->assertForbidden();
        $this->assertSame('created', $shipment->fresh()->status->value);
    }
}
