<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\City;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\ShippingCompany;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\WebhookLog;
use App\Services\InventoryService;
use App\Services\OrderService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderSpecCoverageTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->staff = User::factory()->create();
        $this->staff->givePermissionTo($this->permissions([
            'orders.view', 'orders.update', 'orders.payment', 'inventory.all_branches',
        ]));
        User::factory()->create()->assignRole(Role::findOrCreate('Super Admin', 'staff'));
        $this->branch = Branch::factory()->create();
    }

    public function test_stripe_event_type_classifies_refunds_ahead_of_charge_status(): void
    {
        config(['webhooks.payment.stripe' => [
            'driver' => 'stripe', 'secret' => 'whsec_test', 'currency' => 'EGP',
            'amount_divisor' => 100, 'require_amount' => true,
        ]]);
        $variant = $this->purchasableVariant(500);
        $this->giveStock($variant, 5);
        $refunded = $this->makeOrder($variant, 1, 'card', '01010000001');
        $failed = $this->makeOrder($variant, 1, 'card', '01010000002');
        $paid = $this->makeOrder($variant, 1, 'card', '01010000003');
        $session = $this->makeOrder($variant, 1, 'card', '01010000004');
        foreach ([$refunded, $failed, $paid, $session] as $order) {
            $order->forceFill(['grand_total' => 500])->save();
        }

        $this->stripe([
            'id' => 'evt_refund',
            'type' => 'charge.refunded',
            'data' => ['object' => [
                'id' => 'ch_refund', 'status' => 'succeeded', 'amount' => 50000,
                'currency' => 'egp', 'metadata' => ['order_number' => $refunded->order_number],
            ]],
        ])->assertOk();
        $this->assertSame('refunded', $refunded->fresh()->payment_status);
        $this->assertTrue($refunded->fresh()->needs_attention);
        $this->assertSame(OrderStatus::Pending, $refunded->fresh()->status);

        $this->stripe([
            'id' => 'evt_fail',
            'type' => 'payment_intent.payment_failed',
            'data' => ['object' => [
                'id' => 'pi_fail', 'status' => 'requires_payment_method', 'amount' => 50000,
                'currency' => 'egp', 'metadata' => ['order_number' => $failed->order_number],
            ]],
        ])->assertOk();
        $this->assertSame('failed', $failed->fresh()->payment_status);
        $this->assertSame(OrderStatus::Pending, $failed->fresh()->status);

        $this->stripe([
            'id' => 'evt_ok',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_ok', 'status' => 'succeeded', 'amount' => 50000,
                'currency' => 'egp', 'metadata' => ['order_number' => $paid->order_number],
            ]],
        ])->assertOk();
        $this->assertSame('paid', $paid->fresh()->payment_status);
        $this->assertSame(OrderStatus::Confirmed, $paid->fresh()->status);

        $this->stripe([
            'id' => 'evt_cs',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_ok', 'status' => 'complete', 'payment_status' => 'paid',
                'amount_total' => 50000, 'currency' => 'egp',
                'metadata' => ['order_number' => $session->order_number],
            ]],
        ])->assertOk();
        $this->assertSame('paid', $session->fresh()->payment_status);
        $this->assertSame(OrderStatus::Confirmed, $session->fresh()->status);
    }

    public function test_signed_payment_amount_and_currency_must_match_the_order(): void
    {
        config(['webhooks.payment.stripe' => [
            'driver' => 'stripe', 'secret' => 'whsec_amt', 'currency' => 'EGP',
            'amount_divisor' => 100, 'require_amount' => true,
        ]]);
        $order = Order::factory()->create([
            'payment_method' => 'card', 'payment_status' => 'unpaid',
            'status' => OrderStatus::Confirmed, 'grand_total' => 500,
        ]);

        foreach ([49999 => 'unpaid', 50001 => 'unpaid', 50000 => 'paid'] as $cents => $expected) {
            $this->stripe([
                'id' => 'evt_amt_'.$cents,
                'type' => 'payment_intent.succeeded',
                'data' => ['object' => [
                    'id' => 'pi_'.$cents, 'status' => 'succeeded', 'amount' => $cents,
                    'currency' => 'egp', 'metadata' => ['order_number' => $order->order_number],
                ]],
            ], 'whsec_amt')->assertOk();
            $this->assertSame($expected, $order->fresh()->payment_status);
        }
        $this->assertTrue($order->fresh()->needs_attention);

        $usd = Order::factory()->create([
            'payment_method' => 'card', 'payment_status' => 'unpaid',
            'status' => OrderStatus::Confirmed, 'grand_total' => 500,
        ]);
        $this->stripe([
            'id' => 'evt_usd', 'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_usd', 'status' => 'succeeded', 'amount' => 50000,
                'currency' => 'usd', 'metadata' => ['order_number' => $usd->order_number],
            ]],
        ], 'whsec_amt')->assertOk()->assertJsonPath('message', 'amount mismatch');
        $this->assertSame('unpaid', $usd->fresh()->payment_status);
        $this->assertTrue($usd->fresh()->needs_attention);

        $missing = Order::factory()->create([
            'payment_method' => 'card', 'payment_status' => 'unpaid',
            'status' => OrderStatus::Confirmed, 'grand_total' => 500,
        ]);
        $this->stripe([
            'id' => 'evt_missing', 'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_missing', 'status' => 'succeeded', 'currency' => 'egp',
                'metadata' => ['order_number' => $missing->order_number],
            ]],
        ], 'whsec_amt')->assertOk()->assertJsonPath('message', 'amount mismatch');
        $this->assertSame('unpaid', $missing->fresh()->payment_status);

        config(['webhooks.payment.stripe.require_amount' => false]);
        $optional = Order::factory()->create([
            'payment_method' => 'card', 'payment_status' => 'unpaid',
            'status' => OrderStatus::Confirmed, 'grand_total' => 500,
        ]);
        $this->stripe([
            'id' => 'evt_optional', 'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_optional', 'status' => 'succeeded', 'currency' => 'egp',
                'metadata' => ['order_number' => $optional->order_number],
            ]],
        ], 'whsec_amt')->assertOk();
        $this->assertSame('paid', $optional->fresh()->payment_status);
    }

    public function test_payment_signatures_reject_missing_wrong_stale_and_empty_secrets(): void
    {
        config(['webhooks.payment.stripe' => [
            'driver' => 'stripe', 'secret' => 'whsec_sig', 'currency' => 'EGP', 'amount_divisor' => 100,
        ]]);
        $order = Order::factory()->create([
            'payment_method' => 'card', 'payment_status' => 'unpaid', 'status' => OrderStatus::Pending, 'grand_total' => 100,
        ]);
        $body = json_encode([
            'id' => 'evt_sig', 'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_sig', 'status' => 'succeeded', 'amount' => 10000, 'currency' => 'egp',
                'metadata' => ['order_number' => $order->order_number],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/v1/webhooks/payment/stripe', [], [], [], $this->jsonServer(), $body)
            ->assertForbidden()->assertJsonPath('message', 'invalid signature');
        $this->call('POST', '/api/v1/webhooks/payment/stripe', [], [], [], $this->jsonServer([
            'HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=deadbeef',
        ]), $body)->assertForbidden();
        $stale = time() - 301;
        $this->call('POST', '/api/v1/webhooks/payment/stripe', [], [], [], $this->jsonServer([
            'HTTP_STRIPE_SIGNATURE' => 't='.$stale.',v1='.hash_hmac('sha256', $stale.'.'.$body, 'whsec_sig'),
        ]), $body)->assertForbidden();

        $log = WebhookLog::query()->latest('id')->firstOrFail();
        $this->assertSame('invalid_signature', $log->status);
        $this->assertNull($log->payload);
        $this->assertFalse($log->signature_valid);
        $this->assertSame('unpaid', $order->fresh()->payment_status);

        foreach (['', null] as $secret) {
            config(['webhooks.payment.stripe.secret' => $secret]);
            $this->call('POST', '/api/v1/webhooks/payment/stripe', [], [], [], $this->jsonServer([
                'HTTP_STRIPE_SIGNATURE' => 't='.time().',v1='.hash_hmac('sha256', time().'.'.$body, 'whsec_sig'),
            ]), $body)->assertForbidden();
        }
        $this->postJson('/api/v1/webhooks/payment/not-a-gateway', [])->assertNotFound();
        $this->call('POST', '/api/v1/webhooks/payment/stripe', [], [], [], $this->jsonServer(), str_repeat('x', 262145))
            ->assertStatus(413);
    }

    public function test_sensitive_webhook_keys_are_redacted_regardless_of_case(): void
    {
        config(['webhooks.payment.stripe' => [
            'driver' => 'stripe', 'secret' => 'whsec_red', 'currency' => 'EGP',
            'amount_divisor' => 1, 'require_amount' => false,
        ]]);
        $order = Order::factory()->create([
            'payment_method' => 'card', 'payment_status' => 'unpaid', 'status' => OrderStatus::Confirmed, 'grand_total' => 10,
        ]);
        $this->stripe([
            'id' => 'evt_red', 'type' => 'payment_intent.payment_failed',
            'secret_token' => 'sek', 'Secret-Token' => 'sek2', 'client_secret' => 'cs',
            'nested' => ['api_key' => 'key', 'Authorization' => 'bearer'],
            'data' => ['object' => [
                'id' => 'pi_red', 'status' => 'requires_payment_method',
                'metadata' => ['order_number' => $order->order_number],
            ]],
        ], 'whsec_red')->assertOk();

        $payload = WebhookLog::query()->latest('id')->firstOrFail()->payload;
        $this->assertSame('[redacted]', $payload['secret_token']);
        $this->assertSame('[redacted]', $payload['Secret-Token']);
        $this->assertSame('[redacted]', $payload['client_secret']);
        $this->assertSame('[redacted]', $payload['nested']['api_key']);
        $this->assertSame('[redacted]', $payload['nested']['Authorization']);
    }

    public function test_replaced_shipment_delivery_does_not_deliver_the_order(): void
    {
        $company = ShippingCompany::factory()->create([
            'code' => 'bosta', 'is_active' => true, 'api_credentials' => ['webhook_secret' => 'ship-secret'],
        ]);
        $order = Order::factory()->create(['status' => OrderStatus::Shipped, 'payment_method' => 'cod', 'payment_status' => 'unpaid']);
        Shipment::factory()->create([
            'order_id' => $order->id, 'shipping_company_id' => $company->id,
            'awb_number' => 'AWB-OLD', 'status' => ShipmentStatus::Failed,
        ]);
        Shipment::factory()->create([
            'order_id' => $order->id, 'shipping_company_id' => $company->id,
            'awb_number' => 'AWB-NEW', 'status' => ShipmentStatus::InTransit,
        ]);
        $body = json_encode(['event_id' => 'late-delivered', 'awb_number' => 'AWB-OLD', 'status' => 'delivered'], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/v1/webhooks/shipping/bosta', [], [], [], $this->jsonServer([
            'HTTP_X-Webhook-Signature' => hash_hmac('sha256', $body, 'ship-secret'),
        ]), $body)->assertOk()->assertJsonPath('ignored', true);

        $this->assertSame(OrderStatus::Shipped, $order->fresh()->status);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertSame(ShipmentStatus::Failed, Shipment::query()->where('awb_number', 'AWB-OLD')->firstOrFail()->status);
    }

    public function test_admin_order_filters_use_cairo_days_literal_like_and_normalized_phones(): void
    {
        $cairoNight = Carbon::parse('2026-11-07 23:30:00', 'Africa/Cairo')->utc();
        $dstMorning = Carbon::parse('2023-04-28 00:30:00', 'Africa/Cairo')->utc();
        $match = Order::factory()->create([
            'guest_name' => 'أحمد', 'guest_phone' => '01012345678', 'guest_phone_normalized' => '01012345678',
            'needs_attention' => false, 'created_at' => $cairoNight, 'order_number' => 'ORD-100_PCT',
        ]);
        Order::factory()->create([
            'guest_phone_normalized' => '01099999999', 'needs_attention' => true,
            'created_at' => $dstMorning, 'order_number' => 'ORD-DST',
        ]);
        Order::factory()->create([
            'created_at' => Carbon::parse('2023-04-29 00:15:00', 'Africa/Cairo')->utc(),
            'order_number' => 'ORD-NEXT-LOCAL-DAY',
        ]);

        $this->api('GET', '/api/v1/admin/orders?date_from=2026-11-07&date_to=2026-11-07')
            ->assertOk()->assertJsonFragment(['order_number' => $match->order_number]);
        $this->api('GET', '/api/v1/admin/orders?date_from=2026-11-08&date_to=2026-11-08')
            ->assertOk()->assertJsonMissing(['order_number' => $match->order_number]);
        $this->api('GET', '/api/v1/admin/orders?date_from=2023-04-28&date_to=2023-04-28')
            ->assertOk()->assertJsonFragment(['order_number' => 'ORD-DST'])
            ->assertJsonMissing(['order_number' => 'ORD-NEXT-LOCAL-DAY']);
        $this->api('GET', '/api/v1/admin/orders?search='.urlencode('+201012345678'))
            ->assertOk()->assertJsonFragment(['order_number' => $match->order_number]);
        $this->api('GET', '/api/v1/admin/orders?search='.urlencode('أحمد'))
            ->assertOk()->assertJsonFragment(['order_number' => $match->order_number]);
        $this->api('GET', '/api/v1/admin/orders?search='.urlencode('%'))
            ->assertOk()->assertJsonCount(0, 'data');
        $this->api('GET', '/api/v1/admin/orders?search='.urlencode('_'))
            ->assertOk()->assertJsonMissing(['order_number' => $match->order_number]);
        $this->api('GET', '/api/v1/admin/orders?needs_attention=0')
            ->assertOk()->assertJsonFragment(['order_number' => $match->order_number])
            ->assertJsonMissing(['order_number' => 'ORD-DST']);
        $this->api('GET', '/api/v1/admin/orders?per_page=101')->assertUnprocessable();

        config(['app.business_timezone' => '']);
        $this->api('GET', '/api/v1/admin/orders?date_from=2026-11-07')->assertOk();
    }

    public function test_manual_payment_transitions_are_limited_and_audited(): void
    {
        $allowed = [
            'unpaid' => ['paid', 'failed'],
            'failed' => ['paid', 'unpaid'],
            'paid' => ['refunded'],
        ];
        $statuses = ['unpaid', 'paid', 'failed', 'refunded'];
        foreach ($statuses as $from) {
            foreach ($statuses as $to) {
                if ($from === $to) {
                    continue;
                }
                $order = Order::factory()->create(['payment_status' => $from, 'status' => OrderStatus::Confirmed]);
                $response = $this->api('POST', "/api/v1/admin/orders/{$order->id}/payment", [
                    'payment_status' => $to, 'notes' => 'مراجعة', 'refund_reference' => 'R-1',
                ]);
                $allowedTransition = in_array($to, $allowed[$from] ?? [], true);
                $response->assertStatus($allowedTransition ? 200 : 422);
                $this->assertSame($allowedTransition ? $to : $from, $order->fresh()->payment_status);
                $this->assertSame($allowedTransition ? 1 : 0, $order->statusHistories()->count());
            }
        }

        $paid = Order::factory()->create(['payment_status' => 'unpaid', 'status' => OrderStatus::Confirmed]);
        $this->api('POST', "/api/v1/admin/orders/{$paid->id}/payment", [
            'payment_status' => 'paid', 'notes' => str_repeat('ن', 500),
        ])->assertOk();
        $history = $paid->statusHistories()->latest('id')->firstOrFail();
        $this->assertSame(500, mb_strlen((string) $history->notes));
        $before = $paid->statusHistories()->count();
        $this->api('POST', "/api/v1/admin/orders/{$paid->id}/payment", [
            'payment_status' => 'paid', 'notes' => 'تكرار',
        ])->assertOk();
        $this->assertSame($before, $paid->statusHistories()->count());

        $this->api('POST', "/api/v1/admin/orders/{$paid->id}/payment", [
            'payment_status' => 'refunded', 'notes' => 'استرداد',
        ])->assertUnprocessable();
        $this->assertSame('paid', $paid->fresh()->payment_status);
        $reference = str_repeat('م', 191);
        $this->api('POST', "/api/v1/admin/orders/{$paid->id}/payment", [
            'payment_status' => 'refunded', 'notes' => str_repeat('ن', 500), 'refund_reference' => $reference,
        ])->assertOk();
        $refundNotes = $paid->statusHistories()->latest('id')->firstOrFail()->notes;
        $this->assertStringContainsString($reference, $refundNotes);
        $this->assertSame(500, mb_strlen($refundNotes));

        foreach ([OrderStatus::Cancelled, OrderStatus::Returned] as $status) {
            $closed = Order::factory()->create(['payment_status' => 'unpaid', 'status' => $status]);
            $this->api('POST', "/api/v1/admin/orders/{$closed->id}/payment", [
                'payment_status' => 'paid', 'notes' => 'وصل متأخر',
            ])->assertOk();
            $this->assertTrue($closed->fresh()->requires_refund);
        }

        $this->api('POST', "/api/v1/admin/orders/{$paid->id}/payment", [
            'payment_status' => 'failed', 'notes' => '',
        ])->assertUnprocessable();
    }

    public function test_admin_status_matrix_only_allows_valid_transitions_and_requires_an_awb_to_ship(): void
    {
        $allowed = [
            'pending' => ['confirmed', 'cancelled'],
            'confirmed' => ['processing', 'shipped', 'cancelled'],
            'processing' => ['shipped', 'cancelled'],
            'shipped' => ['delivered', 'returned'],
            'delivered' => ['returned'],
            'cancelled' => [],
            'returned' => [],
        ];
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 1);
        foreach (array_keys($allowed) as $from) {
            foreach (array_keys($allowed) as $to) {
                $order = $from === 'pending' && $to === 'confirmed'
                    ? $this->makeOrder($variant, 1, 'cod')
                    : Order::factory()->create(['status' => $from, 'channel' => 'store']);
                $before = $order->statusHistories()->count();
                $accepted = $to !== 'shipped' && ($from === $to || in_array($to, $allowed[$from], true));
                $response = $this->api('PATCH', "/api/v1/admin/orders/{$order->id}/status", ['status' => $to])
                    ->assertStatus($accepted ? 200 : 422);
                $this->assertSame($accepted ? $to : $from, $order->refresh()->status->value);
                $this->assertSame($before + (int) ($accepted && $from !== $to), $order->statusHistories()->count());
                if ($to === 'shipped') {
                    $this->assertStringContainsString('إنشاء بوليصة', $response->json('message'));
                }
            }
        }

        $pos = Order::factory()->create(['status' => OrderStatus::Delivered, 'channel' => 'pos']);
        $response = $this->api('PATCH', "/api/v1/admin/orders/{$pos->id}/status", ['status' => 'returned'])
            ->assertUnprocessable();
        $this->assertStringContainsString('مرتجع POS', $response->json('message'));
        $this->assertSame(OrderStatus::Delivered, $pos->fresh()->status);
    }

    public function test_account_orders_expose_the_second_page_and_reject_the_wrong_token_type(): void
    {
        $customer = Customer::factory()->create();
        foreach (range(1, 16) as $index) {
            Order::factory()->create(['customer_id' => $customer->id, 'order_number' => sprintf('ORD-PAGE-%02d', $index)]);
        }
        $token = $customer->createToken('account')->plainTextToken;
        Auth::forgetGuards();
        $first = $this->withToken($token)->getJson('/api/v1/store/account/orders')->assertOk();
        $this->assertCount(15, $first->json('data'));
        Auth::forgetGuards();
        $second = $this->withToken($token)->getJson('/api/v1/store/account/orders?page=2')->assertOk();
        $this->assertCount(1, $second->json('data'));
        $this->assertSame(2, $second->json('meta.current_page'));

        Auth::forgetGuards();
        $this->flushHeaders()->getJson('/api/v1/admin/orders')->assertUnauthorized();
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/admin/orders')->assertForbidden();
        $existingId = Order::query()->value('id');
        Auth::forgetGuards();
        $this->withToken($token)->patchJson("/api/v1/admin/orders/{$existingId}/status", ['status' => 'cancelled'])
            ->assertForbidden();
        $this->assertNotSame(OrderStatus::Cancelled, Order::query()->findOrFail($existingId)->status);

        $staffToken = $this->staff->createToken('staff')->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($staffToken)->getJson('/api/v1/store/account/orders')->assertForbidden();

        $operator = User::factory()->create();
        $operator->givePermissionTo($this->permissions(['orders.update', 'inventory.all_branches']));
        Auth::forgetGuards();
        $this->withToken($operator->createToken('pay')->plainTextToken)
            ->postJson('/api/v1/admin/orders/'.Order::factory()->create()->id.'/payment', [
                'payment_status' => 'paid', 'notes' => 'بدون صلاحية',
            ])->assertForbidden();
    }

    public function test_same_idempotency_key_conflicts_on_a_different_total_and_is_scoped_by_phone(): void
    {
        $variant = $this->purchasableVariant(500);
        $this->giveStock($variant, 5);
        $city = City::factory()->create(['shipping_cost' => 0, 'is_active' => true]);
        $headers = ['Idempotency-Key' => 'same-key'];
        $firstCart = $this->cart($variant);
        $payload = [
            'recipient_name' => 'ضيف', 'recipient_phone' => '01011111111',
            'city_id' => $city->id, 'shipping_address_line' => 'شارع 1',
            'payment_method' => 'cod',
        ];
        $this->withHeaders([...$headers, 'X-Cart-Session' => $firstCart])
            ->postJson('/api/v1/store/checkout', [...$payload, 'expected_total' => '500.01'])
            ->assertUnprocessable()->assertJsonPath('code', 'price_changed');
        $created = $this->withHeaders([...$headers, 'X-Cart-Session' => $firstCart])
            ->postJson('/api/v1/store/checkout', $payload)->assertCreated();

        $refilled = $this->cart($variant);
        $this->withHeaders([...$headers, 'X-Cart-Session' => $refilled])
            ->postJson('/api/v1/store/checkout', [...$payload, 'expected_total' => '500.01'])
            ->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $differentCity = City::factory()->create(['shipping_cost' => 0]);
        $this->withHeaders([...$headers, 'X-Cart-Session' => $refilled])
            ->postJson('/api/v1/store/checkout', [...$payload, 'city_id' => $differentCity->id])
            ->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $differentCart = $this->cart($this->purchasableVariant(500));
        $this->withHeaders([...$headers, 'X-Cart-Session' => $differentCart])
            ->postJson('/api/v1/store/checkout', $payload)
            ->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->assertSame(1, Order::query()->count());

        $otherPhone = $this->cart($variant);
        $second = $this->withHeaders([...$headers, 'X-Cart-Session' => $otherPhone])
            ->postJson('/api/v1/store/checkout', [...$payload, 'recipient_phone' => '01022222222'])
            ->assertCreated();
        $this->assertNotSame($created->json('data.order_number'), $second->json('data.order_number'));
        $this->assertSame(2, Order::query()->count());
    }

    public function test_checkout_rejects_invalid_inputs_without_consuming_the_cart_or_stock(): void
    {
        $variant = $this->purchasableVariant(100);
        $unpriced = $this->purchasableVariant(0);
        $this->giveStock($variant, 5);
        $this->giveStock($unpriced, 5);
        $active = City::factory()->create();
        $inactive = City::factory()->create(['is_active' => false]);
        $cart = $this->cart($variant);
        $body = [
            'city_id' => $active->id, 'recipient_name' => 'Customer', 'recipient_phone' => '01012345678',
            'shipping_address_line' => 'Cairo', 'payment_method' => 'cod',
        ];
        $checkout = fn (string $token, array $changes = []) => $this->postJson(
            '/api/v1/store/checkout', array_replace($body, $changes), ['X-Cart-Session' => $token],
        );

        $checkout($cart, ['city_id' => null])->assertUnprocessable()->assertJsonValidationErrors('city_id');
        $checkout($cart, ['city_id' => $inactive->id])->assertUnprocessable();
        $checkout($cart, ['recipient_phone' => '---'])->assertUnprocessable()->assertJsonValidationErrors('recipient_phone');
        $checkout($cart, ['recipient_phone' => '+------'])->assertUnprocessable();
        $checkout(Cart::query()->create([])->cart_token)->assertUnprocessable();
        $checkout($this->cart($unpriced))->assertUnprocessable();

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('cart_items', 2);
        $this->assertSame(0.0, (float) StockLevel::query()->sum('reserved'));
    }

    public function test_order_list_queries_are_bounded_as_the_number_of_orders_grows(): void
    {
        $customer = Customer::factory()->create();
        $token = $customer->createToken('list')->plainTextToken;
        $adminToken = $this->staff->createToken('list')->plainTextToken;
        $variant = $this->purchasableVariant(100);
        $addOrders = function (int $count) use ($customer, $variant): void {
            Order::factory()->count($count)->for($customer)
                ->has(OrderItem::factory()->count(5)->forVariant($variant), 'items')->create();
        };
        $measure = function (string $token, string $url, int $orders, int $total): int {
            Auth::forgetGuards();
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $this->withToken($token)->getJson($url)->assertOk()
                    ->assertJsonCount($orders, 'data')->assertJsonPath('meta.total', $total);

                return count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
        };
        $account = '/api/v1/store/account/orders';
        $admin = '/api/v1/admin/orders?per_page=100';
        $addOrders(15);
        $measure($token, $account, 15, 15);
        $measure($adminToken, $admin, 15, 15);
        $accountQueries = $measure($token, $account, 15, 15);
        $adminQueries = $measure($adminToken, $admin, 15, 15);

        $addOrders(85);
        $this->assertSame($accountQueries, $measure($token, $account, 15, 100));
        $this->assertSame($adminQueries, $measure($adminToken, $admin, 100, 100));
    }

    public function test_checkout_without_the_system_actor_rolls_back_instead_of_using_a_staff_identity(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 5);
        $city = City::factory()->create();
        $cart = $this->cart($variant);
        User::query()->where('is_system', true)->delete();

        $this->postJson('/api/v1/store/checkout', [
            'city_id' => $city->id,
            'recipient_name' => 'Customer',
            'recipient_phone' => '01012345678',
            'shipping_address_line' => 'Cairo',
            'payment_method' => 'cod',
        ], ['X-Cart-Session' => $cart])->assertInternalServerError();

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('order_status_histories', 0);
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame(0.0, (float) StockLevel::query()->sum('reserved'));
    }

    public function test_coupon_budget_is_shared_between_customers_on_the_same_ip_only_for_that_code(): void
    {
        config(['store.coupon_apply_per_minute' => 1]);
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 5);
        $coupon = Coupon::factory()->create(['code' => 'SHARED', 'type' => 'fixed', 'value' => 10]);
        $otherCoupon = Coupon::factory()->create(['code' => 'OTHER', 'type' => 'fixed', 'value' => 10]);
        $first = Customer::factory()->create();
        $second = Customer::factory()->create();
        foreach ([$first, $second] as $customer) {
            Cart::query()->create(['customer_id' => $customer->id])->items()
                ->create(['product_variant_id' => $variant->id, 'qty' => 1]);
        }
        $firstToken = $first->createToken('coupon')->plainTextToken;
        $secondToken = $second->createToken('coupon')->plainTextToken;
        $apply = function (string $token, string $code): TestResponse {
            Auth::forgetGuards();

            return $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
                ->withToken($token)->postJson('/api/v1/store/cart/coupon', ['code' => $code]);
        };

        $apply($firstToken, ' shared ')->assertOk();
        $apply($secondToken, 'SHARED')->assertTooManyRequests();
        $this->assertDatabaseHas('carts', ['customer_id' => $second->id, 'coupon_id' => null]);
        $apply($secondToken, 'OTHER')->assertOk();
        $this->assertDatabaseHas('carts', ['customer_id' => $first->id, 'coupon_id' => $coupon->id]);
        $this->assertDatabaseHas('carts', ['customer_id' => $second->id, 'coupon_id' => $otherCoupon->id]);
    }

    public function test_prune_keeps_processed_event_keys_and_treats_zero_days_as_one(): void
    {
        $old = now()->subDays(100);
        $rows = [];
        for ($index = 1; $index <= 2500; $index++) {
            $rows[] = [
                'provider' => 'payment:stripe', 'event_id' => 'old-processed-'.$index,
                'payload' => '{"secret_token":"x"}', 'signature_valid' => true,
                'status' => 'processed', 'created_at' => $old, 'updated_at' => $old,
            ];
            $rows[] = [
                'provider' => 'payment:stripe', 'event_id' => 'old-failed-'.$index,
                'payload' => '{"secret_token":"x"}', 'signature_valid' => true,
                'status' => 'failed', 'created_at' => $old, 'updated_at' => $old,
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            WebhookLog::query()->insert($chunk);
        }
        $recent = WebhookLog::query()->create([
            'provider' => 'payment:stripe', 'event_id' => 'recent', 'payload' => ['ok' => true],
            'signature_valid' => true, 'status' => 'failed',
        ]);

        $this->artisan('webhooks:prune-logs', ['--days' => 90])
            ->expectsOutputToContain('Pruned 2500 webhook log rows and scrubbed 2500 processed rows')
            ->assertSuccessful();
        $this->assertSame(2500, WebhookLog::query()->where('status', 'processed')->whereNull('payload')->count());
        $this->assertSame(0, WebhookLog::query()->where('status', 'failed')->where('id', '!=', $recent->id)->count());
        $this->assertNotNull($recent->fresh()->payload);

        config(['webhooks.payment.stripe' => [
            'driver' => 'stripe', 'secret' => 'whsec_prune', 'currency' => 'EGP',
            'amount_divisor' => 1, 'require_amount' => false,
        ]]);
        $order = Order::factory()->create([
            'payment_method' => 'card', 'payment_status' => 'unpaid', 'status' => OrderStatus::Confirmed, 'grand_total' => 10,
        ]);
        $payload = [
            'id' => 'evt_keep', 'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_keep', 'status' => 'succeeded', 'amount' => 10, 'currency' => 'egp',
                'metadata' => ['order_number' => $order->order_number],
            ]],
        ];
        $this->stripe($payload, 'whsec_prune')->assertOk();
        $processed = WebhookLog::query()->where('status', 'processed')->where('event_id', 'like', 'pi_keep:%')->firstOrFail();
        $processed->forceFill(['created_at' => $old, 'payload' => ['keep' => true]])->saveQuietly();
        $this->artisan('webhooks:prune-logs', ['--days' => 0])
            ->expectsOutputToContain('older than 1 days')
            ->assertSuccessful();
        $this->assertNull($processed->fresh()->payload);
        $this->stripe($payload, 'whsec_prune')->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(1, WebhookLog::query()->where('event_id', 'like', 'pi_keep:%')->where('status', 'processed')->count());
    }

    /** @param  array<string, mixed>  $payload */
    private function stripe(array $payload, string $secret = 'whsec_test'): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = time();

        return $this->call('POST', '/api/v1/webhooks/payment/stripe', [], [], [], $this->jsonServer([
            'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
        ]), $body);
    }

    /** @param  array<string, string>  $extra */
    private function jsonServer(array $extra = []): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', ...$extra];
    }

    private function api(string $method, string $uri, array $payload = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($this->staff->createToken('admin')->plainTextToken)->json($method, $uri, $payload);
    }

    private function cart(ProductVariant $variant): string
    {
        $cart = Cart::query()->create([]);
        $cart->items()->create(['product_variant_id' => $variant->id, 'qty' => 1]);

        return $cart->cart_token;
    }

    private function makeOrder(ProductVariant $variant, float|int $qty, string $paymentMethod, string $phone = '01012345678'): Order
    {
        $cart = Cart::query()->create([]);
        $cart->items()->create(['product_variant_id' => $variant->id, 'qty' => $qty]);

        return app(OrderService::class)->createFromCart($cart, [
            'recipient_name' => 'عميل', 'recipient_phone' => $phone,
            'payment_method' => $paymentMethod,
        ]);
    }

    private function purchasableVariant(float $price): ProductVariant
    {
        return ProductVariant::factory()->create([
            'product_id' => Product::factory()->create(['status' => 'published'])->id,
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

    /** @param  list<string>  $names */
    private function permissions(array $names): array
    {
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'staff');
        }

        return $names;
    }
}
