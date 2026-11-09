<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Pricing;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Enums\Catalog\PriceListType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\PriceList;
use App\Models\ProductVariant;
use App\Services\Pricing\PricingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * task.txt items 11 (priority matrix), 12 (resolve/context/SQL parity),
 * 18 (dates & timezones) plus section-3 pricing edges.
 */
class P07PricingEngine2Test extends TestCase
{
    use LazilyRefreshDatabase;

    private function variant(float $retail = 100, float $wholesale = 80): ProductVariant
    {
        return ProductVariant::factory()->create(['retail_price' => $retail, 'wholesale_price' => $wholesale]);
    }

    private function priceList(PriceListType $type, array $extra = []): PriceList
    {
        return PriceList::query()->create(array_merge(
            ['name' => 'L'.uniqid(), 'type' => $type, 'priority' => 0], $extra));
    }

    private function customer(CustomerType $type, CustomerStatus $status, ?int $groupId = null): Customer
    {
        return Customer::query()->create(['name' => 'c', 'email' => uniqid().'@example.com',
            'phone' => '010'.random_int(10000000, 99999999), 'password' => Hash::make('password'),
            'company_name' => 'Co', 'tax_number' => '1', 'commercial_register' => '2',
            'type' => $type, 'status' => $status, 'customer_group_id' => $groupId]);
    }

    private function group(float $discount, bool $active = true): CustomerGroup
    {
        return CustomerGroup::query()->create(['name' => 'G'.uniqid(), 'code' => 'G'.uniqid(),
            'discount_percentage' => $discount, 'is_active' => $active]);
    }

    // ── item 11: full priority matrix ──
    #[DataProvider('priorityMatrix')]
    public function test_priority_matrix(callable $setup, callable $resolve, array $expected): void
    {
        $ctx = $setup();
        $out = $resolve($ctx);
        foreach ($expected as $key => $value) {
            $this->assertSame($value, $out[$key], "key {$key}");
        }
    }

    public static function priorityMatrix(): array
    {
        return [
            'guest falls back to variant base price' => [
                function () { return ['variant' => ProductVariant::factory()->create(['retail_price' => 100, 'wholesale_price' => 80])]; },
                function (array $c) { return app(PricingService::class)->resolve($c['variant']); },
                ['unit_price' => 100.0, 'base_price' => 100.0, 'source' => 'base_price', 'price_list_id' => null],
            ],
            'unapproved wholesale is priced as retail' => [
                function () {
                    $v = ProductVariant::factory()->create(['retail_price' => 100, 'wholesale_price' => 80]);
                    $wl = PriceList::query()->create(['name' => 'w', 'type' => PriceListType::Wholesale]);
                    $wl->items()->create(['product_variant_id' => $v->id, 'min_qty' => 1, 'price' => 60]);

                    return ['variant' => $v];
                },
                function (array $c) {
                    $pending = Customer::query()->create(['name' => 'c', 'email' => uniqid().'@e.com', 'phone' => '01012345678',
                        'password' => 'x', 'company_name' => 'C', 'tax_number' => '1', 'commercial_register' => '2',
                        'type' => CustomerType::Wholesale, 'status' => CustomerStatus::Pending]);

                    return app(PricingService::class)->resolve($c['variant'], $pending);
                },
                ['unit_price' => 100.0, 'source' => 'base_price'],
            ],
            'qualified tier wins on quantity' => [
                function () {
                    $v = ProductVariant::factory()->create(['retail_price' => 100, 'wholesale_price' => 80]);
                    $l = PriceList::query()->create(['name' => 'r', 'type' => PriceListType::Retail]);
                    $l->items()->create(['product_variant_id' => $v->id, 'min_qty' => 1, 'price' => 90]);
                    $l->items()->create(['product_variant_id' => $v->id, 'min_qty' => 10, 'price' => 70]);

                    return ['variant' => $v];
                },
                function (array $c) { return app(PricingService::class)->resolve($c['variant'], null, 10); },
                ['unit_price' => 70.0, 'source' => 'retail'],
            ],
            'branch list beats global, global used without branch' => [
                function () {
                    $v = ProductVariant::factory()->create(['retail_price' => 100, 'wholesale_price' => 80]);
                    $b = Branch::factory()->create();
                    $g = PriceList::query()->create(['name' => 'g', 'type' => PriceListType::Retail]);
                    $g->items()->create(['product_variant_id' => $v->id, 'min_qty' => 1, 'price' => 90]);
                    $bl = PriceList::query()->create(['name' => 'b', 'type' => PriceListType::Retail, 'branch_id' => $b->id]);
                    $bl->items()->create(['product_variant_id' => $v->id, 'min_qty' => 1, 'price' => 85]);

                    return ['variant' => $v, 'branch' => $b];
                },
                function (array $c) { return app(PricingService::class)->resolve($c['variant'], null, 1, $c['branch']->id); },
                ['unit_price' => 85.0, 'source' => 'retail'],
            ],
            'higher min_qty wins first; priority breaks min_qty ties; price breaks priority ties' => [
                function () {
                    $v = ProductVariant::factory()->create(['retail_price' => 100, 'wholesale_price' => 80]);
                    $lo = PriceList::query()->create(['name' => 'lo', 'type' => PriceListType::Retail, 'priority' => 1]);
                    $lo->items()->create(['product_variant_id' => $v->id, 'min_qty' => 10, 'price' => 60]);
                    $hi = PriceList::query()->create(['name' => 'hi', 'type' => PriceListType::Retail, 'priority' => 5]);
                    $hi->items()->create(['product_variant_id' => $v->id, 'min_qty' => 1, 'price' => 95]);

                    return ['variant' => $v, 'hi' => $hi];
                },
                function (array $c) { return app(PricingService::class)->resolve($c['variant'], null, 10); },
                ['unit_price' => 60.0],
            ],
            'priority breaks min_qty ties' => [
                function () {
                    $v = ProductVariant::factory()->create(['retail_price' => 100, 'wholesale_price' => 80]);
                    $lo = PriceList::query()->create(['name' => 'lo', 'type' => PriceListType::Retail, 'priority' => 1]);
                    $lo->items()->create(['product_variant_id' => $v->id, 'min_qty' => 1, 'price' => 60]);
                    $hi = PriceList::query()->create(['name' => 'hi', 'type' => PriceListType::Retail, 'priority' => 5]);
                    $hi->items()->create(['product_variant_id' => $v->id, 'min_qty' => 1, 'price' => 95]);

                    return ['variant' => $v, 'hi' => $hi];
                },
                function (array $c) { return app(PricingService::class)->resolve($c['variant'], null, 1); },
                ['unit_price' => 95.0],
            ],
            'lower price breaks priority ties, lower id breaks price ties' => [
                function () {
                    $v = ProductVariant::factory()->create(['retail_price' => 100, 'wholesale_price' => 80]);
                    $a = PriceList::query()->create(['name' => 'a', 'type' => PriceListType::Retail, 'priority' => 3]);
                    $a->items()->create(['product_variant_id' => $v->id, 'min_qty' => 1, 'price' => 70]);
                    $b = PriceList::query()->create(['name' => 'b', 'type' => PriceListType::Retail, 'priority' => 3]);
                    $b->items()->create(['product_variant_id' => $v->id, 'min_qty' => 1, 'price' => 65]);

                    return ['variant' => $v, 'b' => $b];
                },
                function (array $c) { return app(PricingService::class)->resolve($c['variant'], null, 1); },
                ['unit_price' => 65.0],
            ],
            'rounding is HalfUp to two decimals' => [
                function () {
                    $v = ProductVariant::factory()->create(['retail_price' => 100, 'wholesale_price' => 80]);
                    $l = PriceList::query()->create(['name' => 'r', 'type' => PriceListType::Retail]);
                    $l->items()->create(['product_variant_id' => $v->id, 'min_qty' => 1, 'price' => 10.005]);

                    return ['variant' => $v];
                },
                function (array $c) { return app(PricingService::class)->resolve($c['variant']); },
                ['unit_price' => 10.01],
            ],
        ];
    }

    // ── item 11: group discount applies to channel/base only ──
    public function test_group_discount_applies_to_channel_and_base_prices(): void
    {
        $svc = app(PricingService::class);
        $variant = $this->variant(100, 80);

        // 15% group, no lists: base wholesale 80 → 68.
        $group = $this->group(15);
        $customer = $this->customer(CustomerType::Wholesale, CustomerStatus::Approved, $group->id);
        $resolved = $svc->resolve($variant, $customer);
        $this->assertSame(68.0, $resolved['unit_price']);
        $this->assertSame('customer_group_discount', $resolved['source']);

        // 0% group: no discount layer.
        $zero = $this->group(0);
        $c0 = $this->customer(CustomerType::Wholesale, CustomerStatus::Approved, $zero->id);
        $this->assertSame(80.0, $svc->resolve($variant, $c0)['unit_price']);

        // Inactive group behaves as no group.
        $off = $this->group(15, false);
        $cOff = $this->customer(CustomerType::Wholesale, CustomerStatus::Approved, $off->id);
        $this->assertSame(80.0, $svc->resolve($variant, $cOff)['unit_price']);

        // Group-specific list wins over the percentage.
        $gl = $this->priceList(PriceListType::CustomerGroup, ['customer_group_id' => $group->id]);
        $gl->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 55]);
        $this->assertSame(55.0, $svc->resolve($variant, $customer)['unit_price']);

        // 100% group discount prices at zero (owner decision pending).
        $full = $this->group(100);
        $cFull = $this->customer(CustomerType::Wholesale, CustomerStatus::Approved, $full->id);
        $this->assertSame(0.0, $svc->resolve($variant, $cFull)['unit_price']);
    }

    // ── item 12: resolve / contextFor / SQL parity over random scenarios ──
    public function test_resolve_context_and_sql_parity(): void
    {
        mt_srand(20261105);
        $svc = app(PricingService::class);

        for ($s = 0; $s < 25; $s++) {
            $variant = $this->variant(mt_rand(5000, 20000) / 100, mt_rand(3000, 15000) / 100);
            $group = mt_rand(0, 1) === 1 ? $this->group(mt_rand(0, 2000) / 100) : null;
            $customer = $this->customer(
                mt_rand(0, 1) === 1 ? CustomerType::Wholesale : CustomerType::Retail,
                CustomerStatus::Approved, $group?->id);

            foreach (['retail', 'wholesale', 'customer_group', 'customer_specific'] as $i => $type) {
                if (mt_rand(0, 2) === 0) {
                    continue;
                }
                $listType = PriceListType::from($type);
                $extra = [];
                if ($listType === PriceListType::CustomerGroup) {
                    $extra['customer_group_id'] = $group?->id ?? $this->group(5)->id;
                }
                if ($listType === PriceListType::CustomerSpecific) {
                    $extra['customer_id'] = $customer->id;
                }
                $list = $this->priceList($listType, $extra);
                if (mt_rand(0, 3) === 0) {
                    $list->update(['is_active' => false]);
                }
                $tiers = mt_rand(1, 3);
                for ($t = 0; $t < $tiers; $t++) {
                    $list->items()->create(['product_variant_id' => $variant->id,
                        'min_qty' => [1, 5, 10][$t], 'price' => mt_rand(1000, 18000) / 100,
                        'priority' => mt_rand(0, 3),
                        'starts_at' => mt_rand(0, 4) === 0 ? now()->addDay() : null]);
                }
            }

            $ctx = $svc->contextFor($customer, [$variant->id]);
            $direct = $svc->resolve($variant, $customer, 1);
            $viaCtx = $svc->resolve($variant, $customer, 1, null, $ctx);
            $this->assertSame($direct, $viaCtx, "scenario {$s}: ctx parity");

            $row = ProductVariant::query()->whereKey($variant->id);
            $row = $svc->withStorePrice($row, $customer)->first();
            $this->assertSame($direct['unit_price'], (float) $row->store_price, "scenario {$s}: sql parity");

            $tiersDirect = $svc->tiersFor($variant, $customer);
            $tiersCtx = $svc->tiersFor($variant, $customer, $ctx);
            $this->assertSame($tiersDirect, $tiersCtx, "scenario {$s}: tiers parity");
        }
    }

    // ── item 18: date-only ends and Cairo timezones ──
    public function test_date_only_end_and_cairo_timezone_behavior(): void
    {
        $svc = app(PricingService::class);
        $variant = $this->variant(100, 80);

        // Dates go through PriceListService::normalizeDates (Cairo midnight),
        // exactly like the admin API path.
        $list = app(\App\Services\Pricing\PriceListService::class)->create(
            ['name' => 'Dated', 'type' => PriceListType::Retail->value,
                'starts_at' => '2026-11-01', 'ends_at' => '2026-11-05'],
            $this->centralPricingStaff());
        $list->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 60]);

        // A date-only ends_at is stored as Cairo midnight: the list is
        // already over for most of that calendar day in Cairo (owner decision:
        // whether the end day should be fully included).
        $this->assertSame('2026-11-04 22:00:00', $list->fresh()->ends_at->setTimezone('UTC')->format('Y-m-d H:i:s'));

        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', 'Africa/Cairo'));
        try {
            $resolved = $svc->resolve($variant);
        } finally {
            Carbon::setTestNow();
        }
        // At 10:00 Cairo on the end day the list no longer applies → base price.
        $this->assertSame(100.0, $resolved['unit_price']);
        $this->assertSame('base_price', $resolved['source']);

        // DST-aware parsing: an end day inside the DST window (UTC+3) lands
        // one hour earlier in UTC than a winter end day (UTC+2).
        $dstList = app(\App\Services\Pricing\PriceListService::class)->create(
            ['name' => 'Dst', 'type' => PriceListType::Retail->value,
                'starts_at' => '2026-08-01', 'ends_at' => '2026-08-15'],
            $this->centralPricingStaff());
        $this->assertSame('2026-08-14 21:00:00', $dstList->fresh()->ends_at->setTimezone('UTC')->format('Y-m-d H:i:s'));

        // Coupon end-of-day uses app.timezone with no Cairo conversion
        // (owner decision: whether coupons should follow business time).
        // A date-only coupon end means UTC midnight, i.e. ~02:00 Cairo, so a
        // coupon ending "2026-11-05" is still redeemable at 01:00 Cairo but
        // dead at 04:00 Cairo — unlike a price list, which dies at 00:00 Cairo.
        $coupon = \App\Models\Coupon::factory()->create(['code' => 'TZ01', 'ends_at' => '2026-11-05',
            'usage_limit' => null, 'usage_limit_per_customer' => 5]);
        Carbon::setTestNow(Carbon::parse('2026-11-05 01:00:00', 'Africa/Cairo'));
        try {
            $early = $coupon->fresh()->isRedeemableNow();
        } finally {
            Carbon::setTestNow();
        }
        $this->assertTrue($early);
        Carbon::setTestNow(Carbon::parse('2026-11-05 04:00:00', 'Africa/Cairo'));
        try {
            $late = $coupon->fresh()->isRedeemableNow();
        } finally {
            Carbon::setTestNow();
        }
        $this->assertFalse($late);
    }

    // ── section 3: quantity guards, fallbacks, tiers shape ──
    public function test_quantity_guards_and_fallbacks(): void
    {
        $svc = app(PricingService::class);
        $variant = $this->variant(100, 80);

        foreach ([0.0, -1.0, NAN, INF] as $qty) {
            try {
                $svc->resolve($variant, null, $qty);
                $this->fail("expected BusinessException for qty {$qty}");
            } catch (BusinessException $e) {
                $this->assertSame('كمية التسعير يجب أن تكون موجبة.', $e->getMessage());
            }
        }

        // Fractional qty clears a min_qty=1 tier.
        $list = $this->priceList(PriceListType::Retail);
        $list->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 90]);
        $list->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 2, 'price' => 70]);
        $this->assertSame(90.0, $svc->resolve($variant, null, 1.5)['unit_price']);

        // Inactive list and items in an expired list are ignored.
        $dead = $this->priceList(PriceListType::Retail);
        $dead->update(['is_active' => false]);
        $dead->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 5]);
        $old = $this->priceList(PriceListType::Retail);
        $old->update(['starts_at' => now()->subDays(10), 'ends_at' => now()->subDay()]);
        $old->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 4]);
        $this->assertSame(90.0, $svc->resolve($variant, null, 1)['unit_price']);

        // wholesale_price null falls back to retail; both null throws.
        $noWs = ProductVariant::factory()->create(['retail_price' => 100, 'wholesale_price' => null]);
        $approved = $this->customer(CustomerType::Wholesale, CustomerStatus::Approved);
        $this->assertSame(100.0, $svc->resolve($noWs, $approved)['unit_price']);
        $bare = ProductVariant::factory()->create(['retail_price' => null, 'wholesale_price' => null]);
        try {
            $svc->resolve($bare);
            $this->fail('expected BusinessException for unpriced variant');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('غير مُسعَّر', $e->getMessage());
        }

        // tiersFor always starts at 1.0 and ascends.
        $tiers = $svc->tiersFor($variant, $this->customer(CustomerType::Retail, CustomerStatus::Approved));
        $this->assertSame(1.0, $tiers[0]['min_qty']);
        $qtys = array_column($tiers, 'min_qty');
        $sorted = $qtys;
        sort($sorted);
        $this->assertSame($sorted, $qtys);
        $this->assertContains(2.0, $qtys);
    }

    // ── section 3: group code case, disable fallback, REST audit ──
    public function test_customer_group_rules_and_audit(): void
    {
        $svc = app(PricingService::class);
        // Codes are case-sensitive today (owner decision): VIP and vip coexist.
        $upper = CustomerGroup::query()->create(['name' => 'V', 'code' => 'VIP']);
        $lower = CustomerGroup::query()->create(['name' => 'v', 'code' => 'vip']);
        $this->assertNotSame($upper->id, $lower->id);

        // Disabling a group immediately returns members to channel pricing.
        $group = $this->group(20);
        $customer = $this->customer(CustomerType::Wholesale, CustomerStatus::Approved, $group->id);
        $variant = $this->variant(100, 80);
        $this->assertSame(64.0, $svc->resolve($variant, $customer)['unit_price']);
        $group->update(['is_active' => false]);
        $this->assertSame(80.0, $svc->resolve($variant, $customer->fresh())['unit_price']);

        // REST create/update writes audit rows when the actor is passed (service-level).
        $actor = $this->centralPricingStaff();
        $before = \DB::table('staff_audit_logs')->where('action', 'customer_group.created')->count();
        app(\App\Services\Customer\CustomerGroupService::class)->create(
            ['name' => 'Aud', 'code' => 'AUD'.uniqid()], $actor, ['channel' => 'test']);
        $this->assertSame($before + 1, \DB::table('staff_audit_logs')->where('action', 'customer_group.created')->count());
    }

    private function centralPricingStaff(): \App\Models\User
    {
        foreach (['price_lists.view', 'price_lists.create', 'price_lists.update', 'price_lists.delete', 'inventory.all_branches'] as $p) {
            \Spatie\Permission\Models\Permission::findOrCreate($p, 'staff');
        }
        $u = \App\Models\User::factory()->create();
        $u->givePermissionTo(['price_lists.view', 'price_lists.create', 'price_lists.update', 'price_lists.delete', 'inventory.all_branches']);

        return $u;
    }
}
