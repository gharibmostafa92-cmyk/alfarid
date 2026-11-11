<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\Inventory\StockBucket;
use App\Enums\Inventory\StockMovementType;
use App\Enums\Orders\OrderStatus;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WebhookLog;
use App\Services\Inventory\InventoryService;
use App\Services\Orders\OrderService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** task2 H1/M2/M9: payment webhook refund correctness and event filtering. */
class Task2PaymentWebhookTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const SECRET = 'pay-secret';

    private User $staff;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        config(['webhooks.payment.testpay' => [
            'secret' => self::SECRET,
            'signature_header' => 'X-Webhook-Signature',
            'currency' => 'EGP',
            'amount_divisor' => 1,
        ]]);
        $this->staff = User::factory()->create();
        User::factory()->create()->assignRole(Role::findOrCreate('Super Admin', 'staff'));
        $this->branch = Branch::factory()->create();
    }

    public function test_missing_order_returns_conflict_so_the_gateway_redelivers(): void
    {
        // H1: a 2xx tells the gateway "delivered" and a paid order stays pending
        // with no retry. A non-2xx keeps the log failed so redelivery succeeds.
        $this->postWebhook([
            'event_id' => 'evt_missing_order',
            'order_number' => 'ORD-DOES-NOT-EXIST',
            'status' => 'paid',
            'amount' => 100,
        ])->assertStatus(409)->assertJsonPath('received', false);

        $this->assertSame('failed', WebhookLog::query()->latest('id')->first()->status);
    }

    public function test_oversized_body_is_rejected_without_a_webhook_log(): void
    {
        $this->postWebhook(['padding' => str_repeat('x', 256 * 1024)])
            ->assertStatus(413);

        $this->assertSame(0, WebhookLog::query()->count());
    }

    public function test_successive_stripe_refunds_on_the_same_charge_are_all_processed(): void
    {
        $order = $this->paidCardOrder(2);
        $total = (float) $order->grand_total;

        $this->postWebhook($this->refundPayload('evt_r1', $order, ['amount_refunded' => 10000]))
            ->assertOk()->assertJsonPath('received', true);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertTrue($order->fresh()->needs_attention);

        $this->postWebhook($this->refundPayload('evt_r2', $order, [
            'amount_refunded' => (int) round($total * 100),
        ]))->assertOk()->assertJsonPath('received', true);

        $order = $order->fresh();
        $this->assertSame('refunded', $order->payment_status);
        $this->assertSame(3, WebhookLog::query()->where('status', 'processed')->count());
    }

    public function test_pending_refund_object_does_not_mark_order_refunded(): void
    {
        $order = $this->paidCardOrder(2);

        $this->postWebhook([
            'id' => 'evt_refund_obj',
            'type' => 'refund.created',
            'order_number' => $order->order_number,
            'data' => ['object' => ['id' => 're_1', 'amount' => (int) round((float) $order->grand_total * 100), 'currency' => 'EGP', 'status' => 'pending']],
        ])->assertOk()->assertJsonPath('received', true);

        $order = $order->fresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertTrue($order->needs_attention);
    }

    public function test_paymob_unsigned_refund_amount_cannot_mark_order_refunded(): void
    {
        $order = $this->paidCardOrder(2);
        config(['webhooks.payment.paymob' => [
            'driver' => 'paymob',
            'secret' => 'paymob-secret',
            'currency' => 'EGP',
            'amount_divisor' => 100,
        ]]);
        $order->update(['gateway_order_id' => 'pm-order-1']);

        $obj = $this->paymobObj($order, [
            'is_refunded' => true,
            'refunded_amount_cents' => (int) round((float) $order->grand_total * 100),
        ]);
        // L1: without an authenticated cumulative total the refund is unknown;
        // the gateway must redeliver instead of the event being marked processed.
        $this->postPaymob($obj)->assertStatus(503)->assertJsonPath('received', false);

        $order = $order->fresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertFalse($order->needs_attention);
        $this->assertSame('failed', WebhookLog::query()->latest('id')->first()->status);
    }

    public function test_paymob_refund_uses_authenticated_cumulative_amount(): void
    {
        $order = $this->paidCardOrder(2);
        $order->update(['gateway_order_id' => '7654321']);
        config(['webhooks.payment.paymob' => [
            'driver' => 'paymob', 'secret' => 'paymob-secret', 'currency' => 'EGP',
            'api_key' => 'test-inquiry-key',
        ]]);
        $total = (int) round((float) $order->grand_total * 100);
        Http::preventStrayRequests();
        Http::fake([
            'https://accept.paymob.com/api/auth/tokens' => Http::response(['token' => 'test-token']),
            'https://accept.paymob.com/api/acceptance/transactions/123456' => Http::sequence()
                ->push(['id' => 123456, 'order' => ['id' => 7654321], 'currency' => 'EGP',
                    'success' => true, 'pending' => false, 'refunded_amount_cents' => 100])
                ->push(['id' => 123456, 'order' => ['id' => 7654321], 'currency' => 'EGP',
                    'success' => true, 'pending' => false, 'refunded_amount_cents' => $total]),
        ]);
        $callback = $this->paymobObj($order, [
            'id' => 123456, 'is_refunded' => true, 'refunded_amount_cents' => $total,
        ]);

        $this->postPaymob($callback)->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->postPaymob($callback)->assertOk();
        $this->assertSame('refunded', $order->fresh()->payment_status);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/123456')
            && $request->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_refund_on_an_unpaid_order_only_flags_attention(): void
    {
        $order = $this->paidCardOrder(2);
        $order->update(['payment_status' => 'failed']);

        $this->postWebhook([
            'event_id' => 'evt_refund_unpaid',
            'order_number' => $order->order_number,
            'status' => 'refunded',
            'amount_refunded' => (float) $order->grand_total,
        ])->assertOk();

        $order = $order->fresh();
        $this->assertSame('failed', $order->payment_status);
        $this->assertTrue($order->needs_attention);
    }

    public function test_paid_event_on_cancelled_order_raises_attention(): void
    {
        $order = $this->paidCardOrder(1);
        $order->update(['status' => OrderStatus::Cancelled, 'payment_status' => 'unpaid']);

        $this->postWebhook([
            'event_id' => 'evt_cancelled_paid',
            'order_number' => $order->order_number,
            'status' => 'paid',
            'amount' => (float) $order->grand_total,
        ])->assertOk();

        $order = $order->fresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertTrue($order->requires_refund);
        $this->assertTrue($order->needs_attention);
        $this->assertSame(OrderStatus::Cancelled, $order->status);
    }

    public function test_refund_with_mismatched_currency_is_not_applied(): void
    {
        $order = $this->paidCardOrder(2);

        $this->postWebhook([
            'event_id' => 'evt_refund_currency',
            'order_number' => $order->order_number,
            'status' => 'refunded',
            'currency' => 'USD',
            'amount_refunded' => (float) $order->grand_total,
        ])->assertOk();

        $order = $order->fresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertTrue($order->needs_attention);
    }

    public function test_event_id_is_truncated_after_appending_the_outcome(): void
    {
        $order = $this->paidCardOrder(1);

        $this->postWebhook([
            'event_id' => str_repeat('x', 300),
            'order_number' => $order->order_number,
            'status' => 'refunded',
        ])->assertOk();

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertTrue($order->fresh()->needs_attention);

        $eventId = (string) WebhookLog::query()->latest('id')->first()->event_id;
        $this->assertLessThanOrEqual(191, mb_strlen($eventId));
        $this->assertStringEndsWith(':refunded', $eventId);
    }

    public function test_unsubscribed_event_type_is_ignored_when_allowed_events_is_set(): void
    {
        config(['webhooks.payment.testpay.allowed_events' => ['payment_intent.succeeded']]);
        $order = $this->freshCardOrder(1);

        $this->postWebhook([
            'id' => 'evt_charge_ok',
            'type' => 'charge.succeeded',
            'order_number' => $order->order_number,
            'status' => 'paid',
            'amount' => (float) $order->grand_total,
        ])->assertOk()->assertJsonPath('ignored', true);

        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertSame('ignored', WebhookLog::query()->latest('id')->first()->status);
    }

    public function test_paymob_requires_an_amount_by_default(): void
    {
        $order = $this->paidCardOrder(1);
        config(['webhooks.payment.paymob' => [
            'driver' => 'paymob',
            'secret' => 'paymob-secret',
            'currency' => 'EGP',
            'amount_divisor' => 100,
        ]]);
        $order->update(['gateway_order_id' => 'pm-order-2', 'payment_status' => 'unpaid']);

        $obj = $this->paymobObj($order, ['success' => true]);
        unset($obj['amount_cents']);
        $this->postPaymob($obj)->assertOk()->assertJsonPath('received', false);

        $order = $order->fresh();
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertTrue($order->needs_attention);
    }

    public function test_paymob_refund_with_failed_inquiry_returns_service_unavailable(): void
    {
        // L1: a failed refund inquiry (auth error/timeout) leaves the cumulative
        // refund total unknown. Marking processed would lose the refund; fail
        // transiently so Paymob redelivers and the inquiry is retried.
        $order = $this->paidCardOrder(1);
        $order->update(['gateway_order_id' => '7654321']);
        config(['webhooks.payment.paymob' => [
            'driver' => 'paymob', 'secret' => 'paymob-secret', 'currency' => 'EGP',
            'api_key' => 'test-inquiry-key',
        ]]);
        Http::fake([
            'https://accept.paymob.com/api/auth/tokens' => Http::response(['message' => 'denied'], 500),
        ]);

        $processedBefore = WebhookLog::query()->where('status', 'processed')->count();

        $this->postPaymob($this->paymobObj($order, [
            'id' => '123456', 'is_refunded' => true,
            'order' => ['id' => '7654321'],
        ]))->assertStatus(503)->assertJsonPath('received', false);

        $order = $order->fresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertFalse($order->needs_attention);
        $this->assertSame($processedBefore, WebhookLog::query()->where('status', 'processed')->count());
        $this->assertSame('failed', WebhookLog::query()->latest('id')->first()->status);
    }

    public function test_duplicate_processed_event_is_deduplicated_before_storing_payload(): void
    {
        // M3: a redelivered event whose id is already processed must not store
        // another full payload row; a slim duplicate entry is enough.
        $order = $this->freshCardOrder(1);
        $payload = [
            'event_id' => 'evt_dedup_paid',
            'order_number' => $order->order_number,
            'status' => 'paid',
            'amount' => (float) $order->grand_total,
        ];

        $this->postWebhook($payload)->assertOk()->assertJsonPath('received', true);
        $this->postWebhook($payload)->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(1, WebhookLog::query()->whereNotNull('payload')->count());
    }

    public function test_paymob_stores_only_signed_fields(): void
    {
        // M3: the HMAC covers obj only, so persist obj (+type) instead of the
        // full untrusted payload with attacker-controlled extra keys.
        $order = $this->paidCardOrder(1);
        $order->update(['gateway_order_id' => 'pm-order-9']);
        config(['webhooks.payment.paymob' => [
            'driver' => 'paymob', 'secret' => 'paymob-secret', 'currency' => 'EGP',
        ]]);

        $this->postPaymob($this->paymobObj($order))->assertOk();

        $stored = WebhookLog::query()->latest('id')->first()->payload;
        $this->assertEqualsCanonicalizing(['obj', 'type'], array_keys($stored));
    }

    /** @param array<string, mixed> $overrides */
    private function refundPayload(string $eventId, Order $order, array $overrides = []): array
    {
        return [
            'id' => $eventId,
            'type' => 'charge.refunded',
            'order_number' => $order->order_number,
            'data' => ['object' => array_merge(['id' => 'ch_same_charge'], $overrides)],
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function paymobObj(Order $order, array $overrides = []): array
    {
        return array_merge([
            'id' => 'pm-tx-1',
            'amount_cents' => (int) round(((float) $order->grand_total) * 100),
            'created_at' => '2026-11-11T10:00:00',
            'currency' => 'EGP',
            'error_occured' => false,
            'has_parent_transaction' => false,
            'integration_id' => 1,
            'is_3d_secure' => true,
            'is_auth' => false,
            'is_capture' => false,
            'is_refunded' => false,
            'is_standalone_payment' => true,
            'is_voided' => false,
            'order' => ['id' => $order->gateway_order_id],
            'owner' => 1,
            'pending' => false,
            'source_data' => ['pan' => '1234', 'sub_type' => 'VISA', 'type' => 'card'],
            'success' => true,
        ], $overrides);
    }

    /** @param array<string, mixed> $obj */
    private function postPaymob(array $obj): TestResponse
    {
        $fields = [
            'amount_cents', 'created_at', 'currency', 'error_occured',
            'has_parent_transaction', 'id', 'integration_id', 'is_3d_secure',
            'is_auth', 'is_capture', 'is_refunded', 'is_standalone_payment',
            'is_voided', 'order.id', 'owner', 'pending',
            'source_data.pan', 'source_data.sub_type', 'source_data.type', 'success',
        ];
        $concatenated = '';
        foreach ($fields as $field) {
            $value = data_get($obj, $field);
            if (is_bool($value)) {
                $concatenated .= $value ? 'true' : 'false';
            } elseif (is_scalar($value)) {
                $concatenated .= (string) $value;
            }
        }
        $hmac = hash_hmac('sha512', $concatenated, 'paymob-secret');

        return $this->postWebhook(['obj' => $obj], '/api/v1/webhooks/payment/paymob?hmac='.$hmac);
    }

    /** @param array<string, mixed> $payload */
    private function postWebhook(array $payload, ?string $uri = null): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', $uri ?? '/api/v1/webhooks/payment/testpay', [], [], [], [
            'HTTP_X-Webhook-Signature' => hash_hmac('sha256', $body, self::SECRET),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    private function freshCardOrder(int|float $qty): Order
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 10);
        $cart = Cart::create([]);
        $cart->items()->create(['product_variant_id' => $variant->id, 'qty' => $qty]);

        return app(OrderService::class)->createFromCart($cart, [
            'recipient_name' => 'عميل',
            'recipient_phone' => '01012345678',
            'payment_method' => 'card',
        ]);
    }

    private function paidCardOrder(int|float $qty): Order
    {
        $order = $this->freshCardOrder($qty);

        $this->postWebhook([
            'event_id' => 'evt_paid_'.$order->id,
            'order_number' => $order->order_number,
            'status' => 'paid',
            'amount' => (float) $order->grand_total,
        ])->assertOk();

        return $order->fresh();
    }

    private function purchasableVariant(float $price): ProductVariant
    {
        $product = Product::factory()->create(['status' => 'published']);

        return ProductVariant::factory()->create([
            'product_id' => $product->id,
            'retail_price' => $price,
        ]);
    }

    private function giveStock(ProductVariant $variant, int|float $qty): void
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
}
