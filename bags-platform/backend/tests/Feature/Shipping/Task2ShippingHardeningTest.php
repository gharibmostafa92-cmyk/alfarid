<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\Orders\OrderStatus;
use App\Enums\Orders\ShipmentStatus;
use App\Exceptions\BusinessException;
use App\Models\City;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShippingCompany;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use App\Services\Orders\ShipmentService;
use App\Services\Orders\ShippingQuoteService;
use App\Services\Orders\ShippingWebhookService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class Task2ShippingHardeningTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_quote_for_city_rejects_inactive_and_missing_city(): void
    {
        $inactiveCity = City::factory()->create(['is_active' => false, 'shipping_cost' => 50]);

        $this->expectException(BusinessException::class);
        app(ShippingQuoteService::class)->quoteForCity($inactiveCity->id, 100.0, false);
    }

    public function test_quote_for_city_picks_lowest_company_id_deterministically(): void
    {
        $city = City::factory()->create(['is_active' => true]);
        $zone = ShippingZone::factory()->create(['is_active' => true]);
        $zone->cities()->attach($city->id);

        $firstCompany = ShippingCompany::factory()->create(['is_active' => true]);
        $secondCompany = ShippingCompany::factory()->create(['is_active' => true]);

        ShippingRate::factory()->create([
            'shipping_company_id' => $firstCompany->id,
            'shipping_zone_id' => $zone->id,
            'base_cost' => 80.00,
            'free_shipping_threshold' => null,
            'cod_fee' => 10.00,
        ]);
        ShippingRate::factory()->create([
            'shipping_company_id' => $secondCompany->id,
            'shipping_zone_id' => $zone->id,
            'base_cost' => 40.00,
            'free_shipping_threshold' => null,
            'cod_fee' => 5.00,
        ]);

        $quote = app(ShippingQuoteService::class)->quoteForCity($city->id, 100.0, true);
        $this->assertSame(80.0, $quote['cost']);
        $this->assertSame($firstCompany->id, $quote['rate']?->shipping_company_id);
    }

    public function test_shipping_rate_and_shipment_handle_decimal_threshold_and_free_shipping_coupon(): void
    {
        $city = City::factory()->create(['is_active' => true]);
        $zone = ShippingZone::factory()->create(['is_active' => true]);
        $zone->cities()->attach($city->id);
        $company = ShippingCompany::factory()->create(['is_active' => true]);
        $rate = ShippingRate::factory()->create([
            'shipping_company_id' => $company->id,
            'shipping_zone_id' => $zone->id,
            'base_cost' => 60.00,
            'free_shipping_threshold' => 500.00,
        ]);

        // 600.10 - 100.10 in IEEE-754 float is 499.99999999999994
        $floatDiff = (float) '600.10' - (float) '100.10';
        $this->assertTrue($rate->qualifiesForFreeShipping($floatDiff));

        $coupon = Coupon::factory()->create(['type' => 'free_shipping', 'value' => 0]);
        $order = Order::factory()->create([
            'city_id' => $city->id,
            'coupon_id' => $coupon->id,
            'status' => OrderStatus::Shipped,
            'payment_method' => 'cod',
            'subtotal' => 200.00,
            'discount_amount' => 0.00,
            'shipping_cost' => 0.00,
            'grand_total' => 200.00,
        ]);
        Shipment::factory()->create([
            'order_id' => $order->id,
            'shipping_company_id' => $company->id,
            'status' => ShipmentStatus::Failed,
        ]);
        $actor = User::factory()->create();

        $shipment = app(ShipmentService::class)->createForOrder($order, $company->id, null, $actor);
        $this->assertSame('60.00', (string) $shipment->shipping_cost);
    }

    public function test_shipment_tracking_url_rejects_non_https_schemes(): void
    {
        $unsafeCompany = ShippingCompany::factory()->create([
            'tracking_url_template' => 'javascript:alert(1)//{awb}',
        ]);
        $unsafeShipment = Shipment::factory()->create([
            'shipping_company_id' => $unsafeCompany->id,
            'awb_number' => 'AWB-123',
        ]);
        $this->assertNull($unsafeShipment->trackingUrl());

        $safeCompany = ShippingCompany::factory()->create([
            'tracking_url_template' => 'https://track.example.com/{awb}',
        ]);
        $safeShipment = Shipment::factory()->create([
            'shipping_company_id' => $safeCompany->id,
            'awb_number' => 'AWB 123',
        ]);
        $this->assertSame('https://track.example.com/AWB%20123', $safeShipment->trackingUrl());
    }

    public function test_late_returned_webhook_for_replaced_shipment_does_not_flag_delivered_order(): void
    {
        $secret = 'whsec_test_123456';
        $company = ShippingCompany::factory()->create([
            'code' => 'bosta',
            'is_active' => true,
            'api_credentials' => ['webhook_secret' => $secret],
        ]);
        $order = Order::factory()->create([
            'status' => OrderStatus::Delivered,
            'needs_attention' => false,
        ]);
        $oldShipment = Shipment::factory()->create([
            'order_id' => $order->id,
            'shipping_company_id' => $company->id,
            'awb_number' => 'AWB-OLD-1',
            'status' => ShipmentStatus::InTransit,
        ]);
        Shipment::factory()->create([
            'order_id' => $order->id,
            'shipping_company_id' => $company->id,
            'awb_number' => 'AWB-NEW-2',
            'status' => ShipmentStatus::Delivered,
        ]);

        $body = json_encode([
            'event_id' => 'evt-ret-1',
            'awb_number' => 'AWB-OLD-1',
            'status' => 'returned',
        ], JSON_THROW_ON_ERROR);
        $sig = hash_hmac('sha256', $body, $secret);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $res = app(ShippingWebhookService::class)->handle('bosta', json_decode($body, true), $body, $sig, '127.0.0.1');

        $queries = collect(DB::getQueryLog())->pluck('query')->values();
        DB::disableQueryLog();

        $this->assertSame(200, $res['http']);
        $this->assertFalse((bool) $order->fresh()->needs_attention);

        $orderLockIdx = $queries->search(fn (string $q) => stripos($q, 'from "orders"') !== false && stripos($q, 'for update') !== false);
        $shipLockIdx = $queries->search(fn (string $q) => stripos($q, 'from "shipments"') !== false && stripos($q, 'for update') !== false);
        $this->assertNotFalse($orderLockIdx, 'Order must be locked for update in ShippingWebhookService.');
        $this->assertNotFalse($shipLockIdx, 'Shipment must be locked for update in ShippingWebhookService.');
        $this->assertLessThan($shipLockIdx, $orderLockIdx, 'Order lock must precede Shipment lock to prevent deadlocks.');
    }

    public function test_shipping_zone_and_company_destroy_lock_for_update(): void
    {
        foreach (['shipping.view', 'shipping.delete'] as $p) {
            Permission::findOrCreate($p, 'staff');
        }
        $user = User::factory()->create();
        $user->givePermissionTo(['shipping.view', 'shipping.delete']);
        $token = $user->createToken('test')->plainTextToken;

        $zone = ShippingZone::factory()->create();
        $company = ShippingCompany::factory()->create();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->deleteJson("/api/v1/admin/shipping-zones/{$zone->id}")
            ->assertOk();

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->deleteJson("/api/v1/admin/shipping-companies/{$company->id}")
            ->assertOk();

        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertTrue($queries->contains(fn (string $q) => stripos($q, 'from "shipping_zones"') !== false && stripos($q, 'for update') !== false));
        $this->assertTrue($queries->contains(fn (string $q) => stripos($q, 'from "shipping_companies"') !== false && stripos($q, 'for update') !== false));
    }
}
