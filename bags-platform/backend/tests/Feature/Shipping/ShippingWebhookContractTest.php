<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\Orders\OrderStatus;
use App\Enums\Orders\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShippingCompany;
use App\Models\WebhookLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * عقود ويب هوك الشحن: التوقيع، عزل الشركات، آلة الحالات، والتسليم.
 */
class ShippingWebhookContractTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const SECRET = 'S';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_invalid_signatures_are_indistinguishable_and_do_not_touch_the_shipment(): void
    {
        $company = $this->company('bosta', self::SECRET);
        $order = Order::factory()->create(['status' => OrderStatus::Pending, 'payment_status' => 'unpaid']);
        $shipment = Shipment::factory()->create([
            'order_id' => $order->id,
            'shipping_company_id' => $company->id,
            'awb_number' => 'AWB-1',
            'status' => ShipmentStatus::Created,
        ]);
        $body = json_encode(['awb_number' => 'AWB-1', 'status' => 'delivered'], JSON_THROW_ON_ERROR);
        $cases = [
            'missing header' => [$body, null, 'invalid_signature', null],
            'other secret' => [$body, hash_hmac('sha256', $body, 'other'), 'invalid_signature', null],
            'tampered body' => [$body.' ', hash_hmac('sha256', $body, self::SECRET), 'invalid_signature', null],
        ];

        foreach ($cases as [$raw, $signature, $status, $error]) {
            $this->postRaw('/api/v1/webhooks/shipping/bosta', $raw, $signature)
                ->assertForbidden()
                ->assertExactJson(['received' => false, 'message' => 'invalid signature']);
            $log = WebhookLog::query()->latest('id')->firstOrFail();
            $this->assertNull($log->payload);
            $this->assertFalse($log->signature_valid);
            $this->assertSame($status, $log->status);
            $this->assertSame($error, $log->error);
        }

        $this->company('nosecret', null);
        $this->postRaw('/api/v1/webhooks/shipping/nosecret', $body, hash_hmac('sha256', $body, self::SECRET))
            ->assertForbidden()
            ->assertExactJson(['received' => false, 'message' => 'invalid signature']);
        $missingSecret = WebhookLog::query()->latest('id')->firstOrFail();
        $this->assertSame('invalid_signature', $missingSecret->status);
        $this->assertSame('لا يوجد webhook_secret مضبوط للشركة', $missingSecret->error);
        $this->assertNull($missingSecret->payload);
        $this->assertFalse($missingSecret->signature_valid);

        $this->company('paused', self::SECRET, false);
        $this->postRaw('/api/v1/webhooks/shipping/paused', $body, hash_hmac('sha256', $body, self::SECRET))
            ->assertForbidden()
            ->assertExactJson(['received' => false, 'message' => 'invalid signature']);
        $this->postRaw('/api/v1/webhooks/shipping/missing', $body, hash_hmac('sha256', $body, self::SECRET))
            ->assertForbidden()
            ->assertExactJson(['received' => false, 'message' => 'invalid signature']);
        $this->assertSame(
            ['failed', 'failed'],
            WebhookLog::query()->latest('id')->limit(2)->pluck('status')->sort()->values()->all(),
        );

        $this->assertSame(ShipmentStatus::Created, $shipment->fresh()->status);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_a_carrier_cannot_update_another_carriers_waybill(): void
    {
        $companyA = $this->company('carrier-a', 'secret-a');
        $companyB = $this->company('carrier-b', 'secret-b');
        $order = Order::factory()->create(['status' => OrderStatus::Shipped]);
        $shipment = Shipment::factory()->create([
            'order_id' => $order->id,
            'shipping_company_id' => $companyB->id,
            'awb_number' => 'AWB-9',
            'status' => ShipmentStatus::OutForDelivery,
        ]);

        $this->postWebhook('/api/v1/webhooks/shipping/carrier-a', [
            'awb_number' => 'AWB-9', 'status' => 'delivered', 'event_id' => 'cross',
        ], 'secret-a')->assertOk()->assertExactJson(['received' => false, 'message' => 'shipment not found']);

        $log = WebhookLog::query()->sole();
        $this->assertSame('failed', $log->status);
        $this->assertSame('بوليصة غير موجودة: AWB-9', $log->error);
        $this->assertSame(ShipmentStatus::OutForDelivery, $shipment->fresh()->status);
        $this->assertSame(OrderStatus::Shipped, $order->fresh()->status);
        $this->assertSame($companyA->id, $companyA->fresh()->id);
    }

    public function test_delivered_updates_the_order_only_when_it_is_shipped(): void
    {
        $company = $this->company('bosta', self::SECRET);
        $expectations = [
            OrderStatus::Shipped->value => OrderStatus::Delivered,
            OrderStatus::Processing->value => OrderStatus::Processing,
            OrderStatus::Cancelled->value => OrderStatus::Cancelled,
            OrderStatus::Delivered->value => OrderStatus::Delivered,
        ];

        foreach ($expectations as $from => $to) {
            $order = Order::factory()->create([
                'status' => $from,
                'payment_method' => 'cod',
                'payment_status' => 'unpaid',
                'needs_attention' => false,
            ]);
            $shipment = Shipment::factory()->create([
                'order_id' => $order->id,
                'shipping_company_id' => $company->id,
                'awb_number' => 'AWB-'.$from,
                'status' => ShipmentStatus::OutForDelivery,
            ]);

            $this->postWebhook('/api/v1/webhooks/shipping/bosta', [
                'event_id' => 'deliver-'.$from, 'awb_number' => $shipment->awb_number, 'status' => 'delivered',
            ], self::SECRET)->assertOk()->assertExactJson(['received' => true]);

            $order->refresh();
            $log = WebhookLog::query()->where('event_id', 'deliver-'.$from.':delivered')->firstOrFail();
            $this->assertSame('processed', $log->status);
            $this->assertSame(ShipmentStatus::Delivered, $shipment->fresh()->status);
            $this->assertSame($to, $order->status);
            $this->assertFalse($order->needs_attention);
            if ($from === OrderStatus::Shipped->value) {
                $this->assertSame('paid', $order->payment_status);
                $this->assertSame('تم التسليم — إشعار شركة الشحن', $order->statusHistories()->sole()->notes);
            } else {
                $this->assertSame('unpaid', $order->payment_status);
                $this->assertNull($log->error);
                $this->assertSame(0, $order->statusHistories()->count());
            }
        }
    }

    public function test_non_transient_database_error_flags_the_order_inside_the_same_transaction(): void
    {
        [$order, $shipment] = $this->shippedDelivery('AWB-SQL');
        $this->installStatusTrigger('23505');

        try {
            $this->postWebhook('/api/v1/webhooks/shipping/bosta', [
                'event_id' => 'sql-1', 'awb_number' => 'AWB-SQL', 'status' => 'delivered',
            ], self::SECRET)->assertOk()->assertExactJson(['received' => true]);
        } finally {
            $this->dropStatusTrigger();
        }

        $this->assertSame(ShipmentStatus::Delivered, $shipment->fresh()->status);
        $this->assertTrue($order->fresh()->needs_attention);
        $this->assertSame(OrderStatus::Shipped, $order->fresh()->status);
        $this->assertSame('failed', WebhookLog::query()->sole()->status);
        $this->assertSame(0, $order->statusHistories()->count());
    }

    public function test_transient_database_error_rolls_back_and_a_retry_processes_once(): void
    {
        [$order, $shipment] = $this->shippedDelivery('AWB-LOCK');
        $this->installStatusTrigger('40P01');
        $payload = ['event_id' => 'lock-1', 'awb_number' => 'AWB-LOCK', 'status' => 'delivered'];

        try {
            $this->postWebhook('/api/v1/webhooks/shipping/bosta', $payload, self::SECRET)->assertStatus(503);
        } finally {
            $this->dropStatusTrigger();
        }

        $this->assertSame(ShipmentStatus::OutForDelivery, $shipment->fresh()->status);
        $this->assertSame(OrderStatus::Shipped, $order->fresh()->status);
        $this->assertFalse($order->fresh()->needs_attention);
        $first = WebhookLog::query()->sole();
        $this->assertSame('received', $first->status);
        $this->assertTrue($first->signature_valid);

        $this->postWebhook('/api/v1/webhooks/shipping/bosta', $payload, self::SECRET)
            ->assertOk()->assertExactJson(['received' => true]);
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(1, $order->statusHistories()->count());
        $this->assertSame(['received', 'processed'], WebhookLog::query()->orderBy('id')->pluck('status')->all());
    }

    public function test_status_transition_matrix_matches_the_rank_rules(): void
    {
        $company = $this->company('bosta', self::SECRET);
        $shipment = Shipment::factory()->create([
            'shipping_company_id' => $company->id,
            'awb_number' => 'AWB-MATRIX',
            'status' => ShipmentStatus::Created,
        ]);
        $statuses = ['created', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered', 'failed', 'returned'];

        foreach ($statuses as $current) {
            foreach ($statuses as $next) {
                Shipment::query()->whereKey($shipment->id)->update(['status' => $current]);
                $shipment->refresh();
                $event = $current.'-to-'.$next;
                $response = $this->postWebhook('/api/v1/webhooks/shipping/bosta', [
                    'event_id' => $event, 'awb_number' => 'AWB-MATRIX', 'status' => $next,
                ], self::SECRET)->assertOk();
                $allowed = $this->transitionAllowed($current, $next);
                if ($allowed) {
                    $this->assertSame(
                        ['received' => true],
                        $response->json(),
                        $current.' → '.$next.' | '.WebhookLog::query()->latest('id')->value('error'),
                    );
                    $this->assertSame($next, $shipment->fresh()->status->value, $current.' → '.$next);
                    $this->assertSame('processed', WebhookLog::query()->where('event_id', $event.':'.$next)->firstOrFail()->status);
                } else {
                    $response->assertExactJson(['received' => true, 'ignored' => true]);
                    $this->assertSame($current, $shipment->fresh()->status->value);
                    $log = WebhookLog::query()->where('event_id', $event.':'.$next)->firstOrFail();
                    $this->assertSame('ignored', $log->status);
                    $this->assertStringContainsString('حدث متأخر/متراجع', (string) $log->error);
                }
            }
        }
    }

    public function test_failed_can_move_backwards_and_terminal_states_cannot(): void
    {
        $company = $this->company('bosta', self::SECRET);
        $shipment = Shipment::factory()->create([
            'shipping_company_id' => $company->id,
            'awb_number' => 'AWB-PATH',
            'status' => ShipmentStatus::InTransit,
        ]);

        $this->postWebhook('/api/v1/webhooks/shipping/bosta', [
            'event_id' => 'back', 'awb_number' => 'AWB-PATH', 'status' => 'created',
        ], self::SECRET)->assertJsonPath('ignored', true);
        $this->assertSame(ShipmentStatus::InTransit, $shipment->fresh()->status);

        $shipment->update(['status' => ShipmentStatus::OutForDelivery]);
        $this->postWebhook('/api/v1/webhooks/shipping/bosta', [
            'event_id' => 'fail', 'awb_number' => 'AWB-PATH', 'status' => 'failed',
        ], self::SECRET)->assertExactJson(['received' => true]);
        $this->postWebhook('/api/v1/webhooks/shipping/bosta', [
            'event_id' => 'resume', 'awb_number' => 'AWB-PATH', 'status' => 'picked_up',
        ], self::SECRET)->assertExactJson(['received' => true]);
        $this->assertSame(ShipmentStatus::PickedUp, $shipment->fresh()->status);

        $shipment->update(['status' => ShipmentStatus::Delivered]);
        $this->postWebhook('/api/v1/webhooks/shipping/bosta', [
            'event_id' => 'late-return', 'awb_number' => 'AWB-PATH', 'status' => 'returned',
        ], self::SECRET)->assertJsonPath('ignored', true);
        $this->assertSame(ShipmentStatus::Delivered, $shipment->fresh()->status);
    }

    public function test_status_aliases_and_unknown_status(): void
    {
        $company = $this->company('bosta', self::SECRET);
        $shipment = Shipment::factory()->create([
            'shipping_company_id' => $company->id,
            'awb_number' => 'AWB-ALIAS',
            'status' => ShipmentStatus::Created,
        ]);
        $aliases = [
            'Out-For-Delivery' => ShipmentStatus::OutForDelivery,
            'delivery_failed' => ShipmentStatus::Failed,
            'picked_up' => ShipmentStatus::PickedUp,
            'Delivered' => ShipmentStatus::Delivered,
        ];
        $shipment->update(['status' => ShipmentStatus::Created]);
        $this->postWebhook('/api/v1/webhooks/shipping/bosta', [
            'event_id' => 'alias-ofd', 'awb_number' => 'AWB-ALIAS', 'status' => 'Out-For-Delivery',
        ], self::SECRET)->assertOk();
        $this->assertSame(ShipmentStatus::OutForDelivery, $shipment->fresh()->status);

        $this->postWebhook('/api/v1/webhooks/shipping/bosta', [
            'event_id' => 'alias-lost', 'awb_number' => 'AWB-ALIAS', 'status' => 'lost',
        ], self::SECRET)->assertOk()->assertExactJson(['received' => true, 'ignored' => true]);
        $this->assertSame('ignored', WebhookLog::query()->where('event_id', 'like', 'alias-lost%')->firstOrFail()->status);
        $this->assertSame(ShipmentStatus::OutForDelivery, $shipment->fresh()->status);

        $this->postWebhook('/api/v1/webhooks/shipping/bosta', [
            'event_id' => 'alias-rto', 'awb_number' => 'AWB-ALIAS', 'status' => 'rto',
        ], self::SECRET)->assertOk();
        $this->assertSame(ShipmentStatus::Returned, $shipment->fresh()->status);
        $this->assertContains($aliases['delivery_failed'], [ShipmentStatus::Failed]);
    }

    public function test_dedup_key_includes_status_and_falls_back_to_body_hash(): void
    {
        $company = $this->company('bosta', self::SECRET);
        $shipment = Shipment::factory()->create([
            'shipping_company_id' => $company->id,
            'awb_number' => 'AWB-DEDUP',
            'status' => ShipmentStatus::Created,
        ]);

        $this->postWebhook('/api/v1/webhooks/shipping/bosta', [
            'event_id' => 'E1', 'awb_number' => 'AWB-DEDUP', 'status' => 'picked_up',
        ], self::SECRET)->assertExactJson(['received' => true]);
        $this->postWebhook('/api/v1/webhooks/shipping/bosta', [
            'event_id' => 'E1', 'awb_number' => 'AWB-DEDUP', 'status' => 'in_transit',
        ], self::SECRET)->assertExactJson(['received' => true]);
        $this->assertSame(ShipmentStatus::InTransit, $shipment->fresh()->status);

        $this->postWebhook('/api/v1/webhooks/shipping/bosta', [
            'awb_number' => 'AWB-DEDUP', 'status' => 'out_for_delivery', 'timestamp' => '2020-01-01T00:00:00Z',
        ], self::SECRET)->assertExactJson(['received' => true]);
        $this->postWebhook('/api/v1/webhooks/shipping/bosta', [
            'awb_number' => 'AWB-DEDUP', 'status' => 'out_for_delivery', 'timestamp' => '2020-01-01T00:00:00Z',
        ], self::SECRET)->assertJsonPath('duplicate', true);

        $body = '{"awb_number":"AWB-DEDUP","status":"delivered"}';
        $this->postRaw('/api/v1/webhooks/shipping/bosta', $body, hash_hmac('sha256', $body, self::SECRET))
            ->assertExactJson(['received' => true]);
        $spaced = '{"awb_number":"AWB-DEDUP","status":"delivered"} ';
        $this->postRaw('/api/v1/webhooks/shipping/bosta', $spaced, hash_hmac('sha256', $spaced, self::SECRET))
            ->assertJsonMissingPath('duplicate');
        $this->assertSame(2, WebhookLog::query()->where('status', 'processed')->where('event_id', 'like', 'sha256:%')->count());
    }

    public function test_malformed_verified_payload_and_alternate_tracking_key(): void
    {
        $company = $this->company('bosta', self::SECRET);
        $shipment = Shipment::factory()->create([
            'shipping_company_id' => $company->id,
            'awb_number' => 'AWB-ALT',
            'status' => ShipmentStatus::Created,
        ]);
        $bad = '{';
        $this->postRaw('/api/v1/webhooks/shipping/bosta', $bad, hash_hmac('sha256', $bad, self::SECRET))
            ->assertOk()->assertExactJson(['received' => false, 'message' => 'shipment not found']);

        $this->postWebhook('/api/v1/webhooks/shipping/bosta', [
            'event_id' => 'alt', 'data' => ['tracking_number' => 'AWB-ALT'], 'status' => 'picked_up',
        ], self::SECRET)->assertExactJson(['received' => true]);
        $this->assertSame(ShipmentStatus::PickedUp, $shipment->fresh()->status);
        $this->assertNull($shipment->fresh()->raw_response);
    }

    public function test_failed_and_returned_flag_attention_unless_a_newer_shipment_is_active(): void
    {
        $company = $this->company('bosta', self::SECRET);
        $only = Order::factory()->create(['status' => OrderStatus::Shipped, 'needs_attention' => false]);
        Shipment::factory()->create([
            'order_id' => $only->id, 'shipping_company_id' => $company->id,
            'awb_number' => 'AWB-ONLY', 'status' => ShipmentStatus::OutForDelivery,
        ]);
        $this->postWebhook('/api/v1/webhooks/shipping/bosta', [
            'event_id' => 'only-fail', 'awb_number' => 'AWB-ONLY', 'status' => 'failed',
        ], self::SECRET)->assertOk();
        $this->assertTrue($only->fresh()->needs_attention);

        $replaced = Order::factory()->create(['status' => OrderStatus::Shipped, 'needs_attention' => false]);
        Shipment::factory()->create([
            'order_id' => $replaced->id, 'shipping_company_id' => $company->id,
            'awb_number' => 'AWB-OLD', 'status' => ShipmentStatus::OutForDelivery,
        ]);
        Shipment::factory()->create([
            'order_id' => $replaced->id, 'shipping_company_id' => $company->id,
            'awb_number' => 'AWB-NEW', 'status' => ShipmentStatus::InTransit,
        ]);
        $this->postWebhook('/api/v1/webhooks/shipping/bosta', [
            'event_id' => 'old-fail', 'awb_number' => 'AWB-OLD', 'status' => 'failed',
        ], self::SECRET)->assertOk();
        $this->assertFalse($replaced->fresh()->needs_attention);
        $this->assertStringContainsString('استُبدلت بأحدث منها', (string) WebhookLog::query()->where('event_id', 'like', 'old-fail%')->firstOrFail()->error);

        $shipped = Order::factory()->create(['status' => OrderStatus::Shipped, 'needs_attention' => false]);
        Shipment::factory()->create([
            'order_id' => $shipped->id, 'shipping_company_id' => $company->id,
            'awb_number' => 'AWB-RTO', 'status' => ShipmentStatus::InTransit,
        ]);
        $movements = DB::table('stock_movements')->count();
        $this->postWebhook('/api/v1/webhooks/shipping/bosta', [
            'event_id' => 'rto-shipped', 'awb_number' => 'AWB-RTO', 'status' => 'returned',
        ], self::SECRET)->assertOk();
        $this->assertTrue($shipped->fresh()->needs_attention);
        $this->assertSame($movements, DB::table('stock_movements')->count());

        $processing = Order::factory()->create(['status' => OrderStatus::Processing, 'needs_attention' => false]);
        Shipment::factory()->create([
            'order_id' => $processing->id, 'shipping_company_id' => $company->id,
            'awb_number' => 'AWB-RTO-P', 'status' => ShipmentStatus::InTransit,
        ]);
        $this->postWebhook('/api/v1/webhooks/shipping/bosta', [
            'event_id' => 'rto-processing', 'awb_number' => 'AWB-RTO-P', 'status' => 'returned',
        ], self::SECRET)->assertOk();
        $this->assertFalse($processing->fresh()->needs_attention);
        $this->assertSame(OrderStatus::Processing, $processing->fresh()->status);
    }

    public function test_sensitive_payload_keys_are_redacted_at_every_depth(): void
    {
        $company = $this->company('bosta', self::SECRET);
        Shipment::factory()->create([
            'shipping_company_id' => $company->id,
            'awb_number' => 'AWB-RED',
            'status' => ShipmentStatus::Created,
        ]);
        $this->postWebhook('/api/v1/webhooks/shipping/bosta', [
            'event_id' => 'redact',
            'token' => 'x',
            'awb_number' => 'AWB-RED',
            'status' => 'picked_up',
            'data' => ['Authorization' => 'y', 'items' => [['password' => 'z', 'sku' => 'keep']]],
        ], self::SECRET)->assertOk();

        $payload = WebhookLog::query()->sole()->payload;
        $this->assertSame('[redacted]', $payload['token']);
        $this->assertSame('[redacted]', $payload['data']['Authorization']);
        $this->assertSame('[redacted]', $payload['data']['items'][0]['password']);
        $this->assertSame('keep', $payload['data']['items'][0]['sku']);
        $this->assertSame('AWB-RED', $payload['awb_number']);
    }

    public function test_payload_size_limit_rejects_one_byte_over_without_storing_the_body(): void
    {
        $this->company('bosta', self::SECRET);
        $exact = str_repeat('a', 262144);
        $this->postRaw('/api/v1/webhooks/shipping/bosta', $exact, 'not-a-signature')
            ->assertForbidden()
            ->assertExactJson(['received' => false, 'message' => 'invalid signature']);

        $over = str_repeat('b', 262145);
        $this->postRaw('/api/v1/webhooks/shipping/bosta', $over, hash_hmac('sha256', $over, self::SECRET))
            ->assertStatus(413)
            ->assertExactJson(['received' => false, 'message' => 'payload too large']);
        $log = WebhookLog::query()->latest('id')->firstOrFail();
        $this->assertSame('rejected', $log->status);
        $this->assertNull($log->payload);
    }

    public function test_random_company_codes_are_throttled(): void
    {
        $body = '{}';
        $response = null;
        for ($i = 0; $i < 121; $i++) {
            $response = $this->postRaw('/api/v1/webhooks/shipping/missing'.$i, $body, 'x');
        }
        $response->assertStatus(429);
    }

    public function test_shipment_status_check_rejects_values_outside_the_enum(): void
    {
        $company = $this->company('bosta', self::SECRET);
        $order = Order::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('shipments')->insert([
            'order_id' => $order->id,
            'shipping_company_id' => $company->id,
            'awb_number' => 'AWB-BAD',
            'status' => 'lost',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function transitionAllowed(string $current, string $next): bool
    {
        if ($current === $next) {
            return true;
        }
        if (in_array($current, ['delivered', 'returned'], true)) {
            return false;
        }
        if ($next === 'failed' || $current === 'failed') {
            return true;
        }
        if ($next === 'returned') {
            return true;
        }
        $rank = ['created' => 0, 'picked_up' => 1, 'in_transit' => 2, 'out_for_delivery' => 3, 'delivered' => 4];

        return ($rank[$next] ?? 0) >= ($rank[$current] ?? 0);
    }

    /** @return array{0: Order, 1: Shipment} */
    private function shippedDelivery(string $awb): array
    {
        $company = $this->company('bosta', self::SECRET);
        $order = Order::factory()->create([
            'status' => OrderStatus::Shipped,
            'payment_method' => 'cod',
            'payment_status' => 'unpaid',
            'needs_attention' => false,
        ]);
        $shipment = Shipment::factory()->create([
            'order_id' => $order->id,
            'shipping_company_id' => $company->id,
            'awb_number' => $awb,
            'status' => ShipmentStatus::OutForDelivery,
        ]);

        return [$order, $shipment];
    }

    private function installStatusTrigger(string $sqlstate): void
    {
        DB::unprepared("CREATE OR REPLACE FUNCTION shipping_test_fail_status() RETURNS trigger AS $$
            BEGIN
                IF NEW.status = 'delivered' THEN
                    RAISE EXCEPTION 'shipping test' USING ERRCODE = '{$sqlstate}';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql");
        DB::unprepared('DROP TRIGGER IF EXISTS shipping_test_fail_status_trg ON orders');
        DB::unprepared('CREATE TRIGGER shipping_test_fail_status_trg BEFORE UPDATE ON orders FOR EACH ROW EXECUTE FUNCTION shipping_test_fail_status()');
    }

    private function dropStatusTrigger(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS shipping_test_fail_status_trg ON orders');
        DB::unprepared('DROP FUNCTION IF EXISTS shipping_test_fail_status()');
    }

    private function company(string $code, ?string $secret, bool $active = true): ShippingCompany
    {
        return ShippingCompany::factory()->create([
            'code' => $code,
            'is_active' => $active,
            'api_credentials' => $secret === null ? null : ['webhook_secret' => $secret],
        ]);
    }

    private function postWebhook(string $uri, array $payload, string $secret): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->postRaw($uri, $body, hash_hmac('sha256', $body, $secret));
    }

    private function postRaw(string $uri, string $body, ?string $signature): TestResponse
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];
        if ($signature !== null) {
            $server['HTTP_X-Webhook-Signature'] = $signature;
        }

        return $this->call('POST', $uri, [], [], [], $server, $body);
    }
}
