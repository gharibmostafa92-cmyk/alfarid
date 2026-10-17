<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingCompany;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WebhookLog;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\ShipmentService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * المرحلة 8.3: ويب هوكس الدفع والشحن — توقيع HMAC، تسجيل مسبق في
 * webhook_logs، عدم تكرار المعالجة، وتأثيرات الطلب التلقائية.
 */
class WebhookTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const PAY_SECRET = 'pay-secret';

    private const SHIP_SECRET = 'ship-secret';

    private User $staff;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        config(['webhooks.payment.testpay' => [
            'secret' => self::PAY_SECRET,
            'signature_header' => 'X-Webhook-Signature',
        ]]);
        $this->staff = User::factory()->create();
        // منفّذ نظام: تأكيد الويب هوك بلا فاعل موظف — يسقط على أول Super Admin
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(Role::findOrCreate('Super Admin', 'staff'));
        $this->branch = Branch::factory()->create();
    }

    public function test_payment_success_marks_paid_confirms_and_allocates(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 10);
        $order = $this->makeOrder($variant, 2, 'card');
        $this->assertSame(OrderStatus::Pending, $order->status);

        $this->postWebhook('/api/v1/webhooks/payment/testpay', [
            'event_id' => 'evt_1', 'order_number' => $order->order_number, 'status' => 'paid',
        ], self::PAY_SECRET)->assertOk()->assertJsonPath('received', true);

        $order = $order->fresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame($this->branch->id, $order->allocated_branch_id);
        $this->assertSame('2.0000', (string) $this->level($variant->id)->reserved);
        $this->assertSame('processed', WebhookLog::query()->sole()->status);
        $this->assertTrue(WebhookLog::query()->sole()->signature_valid);
    }

    public function test_payment_webhook_rejects_bad_signature_and_logs_it(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 5);
        $order = $this->makeOrder($variant, 1, 'card');

        $this->postWebhook('/api/v1/webhooks/payment/testpay', [
            'event_id' => 'evt_bad', 'order_number' => $order->order_number, 'status' => 'paid',
        ], 'wrong-secret')->assertForbidden();

        $order = $order->fresh();
        $this->assertSame('unpaid', $order->payment_status); // دفع كارت يبدأ unpaid
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame('invalid_signature', WebhookLog::query()->sole()->status);
    }

    public function test_payment_webhook_duplicate_event_is_not_reprocessed(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 10);
        $order = $this->makeOrder($variant, 2, 'card');
        $payload = ['event_id' => 'evt_dup', 'order_number' => $order->order_number, 'status' => 'paid'];

        $this->postWebhook('/api/v1/webhooks/payment/testpay', $payload, self::PAY_SECRET)->assertOk();
        $histories = $order->statusHistories()->count();
        $movements = StockMovement::query()->where('type', StockMovementType::Reserve)->count();

        $this->postWebhook('/api/v1/webhooks/payment/testpay', $payload, self::PAY_SECRET)
            ->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame($histories, $order->statusHistories()->count());
        $this->assertSame($movements, StockMovement::query()->where('type', StockMovementType::Reserve)->count());
        $this->assertSame('2.0000', (string) $this->level($variant->id)->reserved);
        $this->assertSame('duplicate', WebhookLog::query()->latest('id')->first()->status);
    }

    public function test_payment_failed_keeps_order_pending_and_unknown_gateway_404(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 5);
        $order = $this->makeOrder($variant, 1, 'card');

        $this->postWebhook('/api/v1/webhooks/payment/testpay', [
            'event_id' => 'evt_fail', 'order_number' => $order->order_number, 'status' => 'failed',
        ], self::PAY_SECRET)->assertOk();

        $order = $order->fresh();
        $this->assertSame('failed', $order->payment_status);
        $this->assertSame(OrderStatus::Pending, $order->status);

        $this->postWebhook('/api/v1/webhooks/payment/nosuch', [
            'order_number' => $order->order_number, 'status' => 'paid',
        ], self::PAY_SECRET)->assertNotFound();
    }

    public function test_shipping_delivered_webhook_delivers_order_and_settles_cod(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 5);
        $order = $this->makeOrder($variant, 1, 'cod');
        app(OrderService::class)->changeStatus($order, OrderStatus::Confirmed, $this->staff);

        $company = ShippingCompany::factory()->create([
            'code' => 'bosta',
            'api_credentials' => ['webhook_secret' => self::SHIP_SECRET],
        ]);
        app(ShipmentService::class)->createForOrder($order, $company->id, 'BOSTA-123', $this->staff);
        $this->assertSame(OrderStatus::Shipped, $order->fresh()->status);

        $this->postWebhook('/api/v1/webhooks/shipping/bosta', [
            'event_id' => 'ev_ship_1', 'awb_number' => 'BOSTA-123', 'status' => 'delivered',
        ], self::SHIP_SECRET)->assertOk()->assertJsonPath('received', true);

        $order = $order->fresh();
        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertSame('paid', $order->payment_status); // COD يُحصّل عند التسليم
        $this->assertSame('delivered', $order->shipments()->sole()->status->value);
    }

    public function test_shipping_webhook_unknown_awb_and_bad_signature(): void
    {
        ShippingCompany::factory()->create([
            'code' => 'bosta',
            'api_credentials' => ['webhook_secret' => self::SHIP_SECRET],
        ]);

        $this->postWebhook('/api/v1/webhooks/shipping/bosta', [
            'event_id' => 'ev_x', 'awb_number' => 'NOPE-1', 'status' => 'in_transit',
        ], self::SHIP_SECRET)->assertOk()->assertJsonPath('received', false);
        $this->assertSame('failed', WebhookLog::query()->sole()->status);

        $this->postWebhook('/api/v1/webhooks/shipping/bosta', [
            'event_id' => 'ev_y', 'awb_number' => 'NOPE-1', 'status' => 'delivered',
        ], 'bad')->assertForbidden();
    }

    // ---------- helpers ----------

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
            'created_by' => $this->staff->id,
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
