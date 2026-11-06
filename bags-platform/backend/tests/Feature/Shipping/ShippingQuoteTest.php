<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Models\City;
use App\Models\ShippingCompany;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Services\ShippingQuoteService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ShippingQuoteTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ShippingQuoteService $quotes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->quotes = app(ShippingQuoteService::class);
    }

    public function test_free_shipping_threshold_and_cod_fee(): void
    {
        $city = $this->ratedCity(50, 500, 12);

        $this->assertSame(50.0, $this->quotes->quoteForCity($city->id, 499.99, false)['cost']);
        $this->assertSame(0.0, $this->quotes->quoteForCity($city->id, 500, false)['cost']);
        $this->assertSame(0.0, $this->quotes->quoteForCity($city->id, 500.01, true)['cost']);
        $this->assertSame(12.0, $this->quotes->quoteForCity($city->id, 10, true)['cod_fee']);
        $this->assertSame(0.0, $this->quotes->quoteForCity($city->id, 10, false)['cod_fee']);

        ShippingRate::query()->update(['free_shipping_threshold' => 0]);
        $this->assertSame(0.0, $this->quotes->quoteForCity($city->id, 0, false)['cost']);
        $this->assertSame(0.0, $this->quotes->quoteForCity($city->id, 1, false)['cost']);

        ShippingRate::query()->update(['free_shipping_threshold' => null]);
        $this->assertSame(50.0, $this->quotes->quoteForCity($city->id, 100000, false)['cost']);
    }

    public function test_city_outside_every_zone_uses_the_flat_fallback(): void
    {
        $city = City::factory()->create(['shipping_cost' => 30, 'is_active' => true]);
        $quote = $this->quotes->quoteForCity($city->id, 1000, true);

        $this->assertSame(30.0, $quote['cost']);
        $this->assertSame(0.0, $quote['cod_fee']);
        $this->assertNull($quote['rate']);
    }

    public function test_the_lowest_company_id_wins_until_it_or_its_zone_is_inactive(): void
    {
        $city = City::factory()->create(['shipping_cost' => 30]);
        $expensive = ShippingCompany::factory()->create(['is_active' => true]);
        $cheap = ShippingCompany::factory()->create(['is_active' => true]);
        $this->assertLessThan($cheap->id, $expensive->id);
        $zoneA = ShippingZone::factory()->create(['is_active' => true]);
        $zoneB = ShippingZone::factory()->create(['is_active' => true]);
        $zoneA->cities()->sync([$city->id]);
        $zoneB->cities()->sync([$city->id]);
        ShippingRate::factory()->create([
            'shipping_company_id' => $expensive->id, 'shipping_zone_id' => $zoneA->id, 'base_cost' => 80,
        ]);
        ShippingRate::factory()->create([
            'shipping_company_id' => $cheap->id, 'shipping_zone_id' => $zoneB->id, 'base_cost' => 40,
        ]);

        $this->assertSame(80.0, $this->quotes->quoteForCity($city->id, 10, false)['cost']);
        $expensive->update(['is_active' => false]);
        $this->assertSame(40.0, $this->quotes->quoteForCity($city->id, 10, false)['cost']);
        $zoneB->update(['is_active' => false]);
        $this->assertSame(30.0, $this->quotes->quoteForCity($city->id, 10, false)['cost']);
    }

    public function test_an_inactive_zone_is_skipped_and_orphan_city_links_do_not_error(): void
    {
        $city = City::factory()->create(['shipping_cost' => 15]);
        $company = ShippingCompany::factory()->create();
        $active = ShippingZone::factory()->create(['is_active' => true]);
        $inactive = ShippingZone::factory()->create(['is_active' => false]);
        $active->cities()->sync([$city->id]);
        $inactive->cities()->sync([$city->id]);
        ShippingRate::factory()->create([
            'shipping_company_id' => $company->id, 'shipping_zone_id' => $inactive->id, 'base_cost' => 1,
        ]);
        ShippingRate::factory()->create([
            'shipping_company_id' => $company->id, 'shipping_zone_id' => $active->id, 'base_cost' => 22,
        ]);
        $this->assertSame(22.0, $this->quotes->quoteForCity($city->id, 10, false)['cost']);

        $cityId = $city->id;
        $city->delete();
        $orphan = $this->quotes->quoteForCity($cityId, 10, false);
        $this->assertSame(22.0, $orphan['cost']);
    }

    public function test_quote_invariants_hold_for_random_amounts(): void
    {
        $city = $this->ratedCity(50, 500, 12);
        for ($i = 0; $i < 40; $i++) {
            $base = round(random_int(0, 500000) / 100, 2);
            $threshold = random_int(0, 1) === 0 ? null : round(random_int(0, 100000) / 100, 2);
            $codFee = round(random_int(0, 2000) / 100, 2);
            $merchandise = [-10.0, 0.0, 99999999.99, random_int(0, 200000) / 100][random_int(0, 3)];
            $cod = (bool) random_int(0, 1);
            ShippingRate::query()->update([
                'base_cost' => $base,
                'free_shipping_threshold' => $threshold,
                'cod_fee' => $codFee,
            ]);
            $low = $this->quotes->quoteForCity($city->id, $merchandise, $cod);
            $high = $this->quotes->quoteForCity($city->id, $merchandise + 100, $cod);

            $this->assertTrue(abs($low['cost']) < 0.001 || abs($low['cost'] - $base) < 0.001, $low['cost'].' vs '.$base);
            $this->assertEqualsWithDelta($cod ? $codFee : 0.0, (float) $low['cod_fee'], 0.001);
            $this->assertLessThanOrEqual($low['cost'], $high['cost']);
            if ($merchandise < 0 || $merchandise === 0.0) {
                $this->assertIsFloat($low['cost']);
            }
        }
    }

    private function ratedCity(float $base, ?float $threshold, float $cod): City
    {
        $city = City::factory()->create(['shipping_cost' => 30]);
        $zone = ShippingZone::factory()->create(['is_active' => true]);
        $zone->cities()->sync([$city->id]);
        ShippingRate::factory()->create([
            'shipping_company_id' => ShippingCompany::factory()->create()->id,
            'shipping_zone_id' => $zone->id,
            'base_cost' => $base,
            'free_shipping_threshold' => $threshold,
            'cod_fee' => $cod,
            'estimated_days_min' => 2,
            'estimated_days_max' => 4,
        ]);

        return $city;
    }
}
