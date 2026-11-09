<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Enums\Catalog\PriceListType;
use App\Enums\Inventory\StockBucket;
use App\Enums\Inventory\StockMovementType;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\City;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\ShippingCompany;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\Shared\SettingService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class CheckoutShippingContractTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    private Branch $branch;

    private ProductVariant $variant;

    private Customer $customer;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(SettingSeeder::class);
        config([
            'store.payment_methods' => ['cod', 'wallet'],
            'store.customer_pending_orders_cap' => 500,
            'store.coupon_apply_per_minute' => 1000,
        ]);
        $this->staff = User::factory()->create();
        $this->branch = Branch::factory()->create();
        $this->variant = ProductVariant::factory()->create([
            'product_id' => Product::factory()->create(['status' => 'published'])->id,
            'retail_price' => 100,
        ]);
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $this->variant->id,
            'branch_id' => $this->branch->id,
            'type' => StockMovementType::AdjustmentIn,
            'affected_bucket' => StockBucket::OnHand,
            'quantity' => 200,
            'created_by' => $this->staff->id,
        ]);
        $this->customer = Customer::factory()->create([
            'type' => CustomerType::Retail,
            'status' => CustomerStatus::Approved,
            'wallet_balance' => 1000000,
        ]);
        $this->city = City::factory()->create(['shipping_cost' => 30, 'is_active' => true]);
        $zone = ShippingZone::factory()->create(['is_active' => true]);
        $zone->cities()->sync([$this->city->id]);
        ShippingRate::factory()->create([
            'shipping_company_id' => ShippingCompany::factory()->create()->id,
            'shipping_zone_id' => $zone->id,
            'base_cost' => 50,
            'free_shipping_threshold' => 100000,
            'cod_fee' => 12,
            'estimated_days_min' => 2,
            'estimated_days_max' => 4,
        ]);
    }

    public function test_preview_grand_total_matches_the_order_for_every_pricing_combination(): void
    {
        $list = PriceList::query()->create(['name' => 'Retail sale', 'type' => PriceListType::Retail, 'is_active' => false]);
        PriceListItem::query()->create([
            'price_list_id' => $list->id,
            'product_variant_id' => $this->variant->id,
            'min_qty' => 1,
            'price' => 80,
        ]);
        $coupons = [
            'percentage' => Coupon::factory()->create(['type' => 'percentage', 'value' => 10, 'usage_limit_per_customer' => 100]),
            'fixed' => Coupon::factory()->create(['type' => 'fixed', 'value' => 15, 'usage_limit_per_customer' => 100]),
            'free_shipping' => Coupon::factory()->create(['type' => 'free_shipping', 'value' => 0, 'usage_limit_per_customer' => 100]),
            'none' => null,
        ];
        $token = $this->customer->createToken('checkout')->plainTextToken;

        foreach ([false, true] as $priced) {
            $list->update(['is_active' => $priced]);
            foreach (['cod', 'wallet'] as $payment) {
                foreach ([false, true] as $above) {
                    ShippingRate::query()->update(['free_shipping_threshold' => $above ? 1 : 100000]);
                    foreach ([
                        ['tax' => false, 'included' => false],
                        ['tax' => true, 'included' => false],
                        ['tax' => true, 'included' => true],
                    ] as $tax) {
                        $this->tax($tax['tax'], $tax['included'], '14');
                        foreach ($coupons as $name => $coupon) {
                            $cart = Cart::query()->updateOrCreate(
                                ['customer_id' => $this->customer->id],
                                ['coupon_id' => $coupon?->id],
                            );
                            $cart->items()->delete();
                            $cart->items()->create(['product_variant_id' => $this->variant->id, 'qty' => 1]);
                            RateLimiter::clear(md5('checkoutcheckout-customer:'.$this->customer->id));
                            RateLimiter::clear(md5('checkoutcheckout-customer-day:'.$this->customer->id));
                            $previewResponse = $this->withToken($token)->postJson('/api/v1/store/checkout/shipping-options', [
                                'city_id' => $this->city->id,
                                'payment_method' => $payment,
                            ]);
                            $this->assertSame(200, $previewResponse->status(), $previewResponse->getContent());
                            $preview = $previewResponse->json('data.preview');
                            $label = json_encode(compact('name', 'payment', 'above', 'tax', 'priced'));
                            if ($name === 'free_shipping' && $payment === 'cod' && ! $above) {
                                $this->assertSame(0, (int) $preview['shipping'], $label);
                                $this->assertEquals(12, (float) $preview['cod_fee'], $label);
                            }
                            if ($priced) {
                                $this->assertLessThan(100, (float) $preview['items_total'], $label);
                            }
                            $before = Order::query()->count();
                            $order = $this->withToken($token)->postJson('/api/v1/store/checkout', [
                                'city_id' => $this->city->id,
                                'shipping_address_line' => 'شارع الاختبار',
                                'payment_method' => $payment,
                                'expected_total' => $preview['grand_total'],
                            ])->assertCreated();
                            $this->assertEqualsWithDelta(
                                (float) $preview['grand_total'],
                                (float) $order->json('data.grand_total'),
                                0.001,
                                $label,
                            );
                            $this->assertSame($before + 1, Order::query()->count());
                        }
                    }
                }
            }
        }
    }

    public function test_a_price_change_between_preview_and_checkout_is_rejected(): void
    {
        $token = $this->customer->createToken('checkout')->plainTextToken;
        $cart = Cart::query()->create(['customer_id' => $this->customer->id]);
        $cart->items()->create(['product_variant_id' => $this->variant->id, 'qty' => 1]);
        $total = $this->withToken($token)->postJson('/api/v1/store/checkout/shipping-options', [
            'city_id' => $this->city->id, 'payment_method' => 'cod',
        ])->json('data.preview.grand_total');
        $this->variant->update(['retail_price' => 140]);

        $this->withToken($token)->postJson('/api/v1/store/checkout', [
            'city_id' => $this->city->id,
            'shipping_address_line' => 'شارع',
            'payment_method' => 'cod',
            'expected_total' => $total,
        ])->assertStatus(422)->assertJsonPath('code', 'price_changed');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_disallowed_payment_methods_fall_back_to_the_first_allowed_one(): void
    {
        $zone = ShippingZone::query()->firstOrFail();
        $guestCart = Cart::query()->create([]);
        $guestCart->items()->create(['product_variant_id' => $this->variant->id, 'qty' => 1]);
        $guest = $this->withHeader('X-Cart-Session', $guestCart->cart_token)
            ->postJson('/api/v1/store/checkout/shipping-options', [
                'city_id' => $this->city->id, 'payment_method' => 'wallet',
            ])->assertOk();
        $this->assertSame(['cod'], $guest->json('data.payment_methods'));
        $this->assertEquals(12, (float) $guest->json('data.preview.cod_fee'));

        Auth::forgetGuards();
        $token = $this->customer->createToken('retail')->plainTextToken;
        $retail = $this->withToken($token)->postJson('/api/v1/store/checkout/shipping-options', [
            'city_id' => $this->city->id, 'payment_method' => 'credit',
        ])->assertOk();
        $this->assertSame(['cod', 'wallet'], $retail->json('data.payment_methods'));
        $this->assertEquals(12, (float) $retail->json('data.preview.cod_fee'));

        Auth::forgetGuards();
        $wholesale = Customer::factory()->create([
            'type' => CustomerType::Wholesale, 'status' => CustomerStatus::Approved, 'wallet_balance' => 0,
        ]);
        $methods = $this->withToken($wholesale->createToken('w')->plainTextToken)
            ->postJson('/api/v1/store/checkout/shipping-options', ['city_id' => $this->city->id])
            ->assertOk()->json('data.payment_methods');
        $this->assertSame(['cod', 'wallet', 'credit'], $methods);

        $pending = Customer::factory()->create(['type' => CustomerType::Retail, 'status' => CustomerStatus::Pending]);
        Auth::forgetGuards();
        $this->assertSame(
            ['cod'],
            $this->withToken($pending->createToken('p')->plainTextToken)
                ->postJson('/api/v1/store/checkout/shipping-options', ['city_id' => $this->city->id])
                ->json('data.payment_methods'),
        );
        $this->assertNotNull($zone->id);
    }

    public function test_shipping_option_inputs_and_empty_guest_cart(): void
    {
        $inactive = City::factory()->create(['is_active' => false]);
        $this->postJson('/api/v1/store/checkout/shipping-options', ['city_id' => $inactive->id])->assertNotFound();
        $this->postJson('/api/v1/store/checkout/shipping-options', ['city_id' => 999999])->assertStatus(422);
        $this->postJson('/api/v1/store/checkout/shipping-options', ['city_id' => 'abc'])->assertStatus(422);
        $this->postJson('/api/v1/store/checkout/shipping-options', [
            'city_id' => $this->city->id, 'payment_method' => 'bitcoin',
        ])->assertStatus(422);

        $empty = $this->postJson('/api/v1/store/checkout/shipping-options', ['city_id' => $this->city->id])->assertOk();
        $this->assertEquals(0, (float) $empty->json('data.preview.items_total'));
        $this->assertIsNumeric($empty->json('data.preview.grand_total'));
    }

    public function test_tax_rounding_days_and_response_shape(): void
    {
        $this->tax(true, false, 'not-a-number');
        $token = $this->customer->createToken('tax')->plainTextToken;
        $cart = Cart::query()->create(['customer_id' => $this->customer->id]);
        $cart->items()->create(['product_variant_id' => $this->variant->id, 'qty' => 1]);
        $odd = ProductVariant::factory()->create([
            'product_id' => $this->variant->product_id,
            'retail_price' => 0.1,
        ]);
        $other = ProductVariant::factory()->create([
            'product_id' => $this->variant->product_id,
            'retail_price' => 0.2,
        ]);
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $odd->id, 'branch_id' => $this->branch->id,
            'type' => StockMovementType::AdjustmentIn, 'affected_bucket' => StockBucket::OnHand,
            'quantity' => 5, 'created_by' => $this->staff->id,
        ]);
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $other->id, 'branch_id' => $this->branch->id,
            'type' => StockMovementType::AdjustmentIn, 'affected_bucket' => StockBucket::OnHand,
            'quantity' => 5, 'created_by' => $this->staff->id,
        ]);
        $cart->items()->delete();
        $cart->items()->create(['product_variant_id' => $odd->id, 'qty' => 1]);
        $cart->items()->create(['product_variant_id' => $other->id, 'qty' => 1]);
        $preview = $this->withToken($token)->postJson('/api/v1/store/checkout/shipping-options', [
            'city_id' => $this->city->id, 'payment_method' => 'wallet',
        ])->assertOk()->json('data');
        $this->assertEquals(0, (float) $preview['preview']['tax']);
        $this->assertSame('من 2 إلى 4 أيام عمل', $preview['options'][0]['estimated_days']);
        $this->assertEqualsWithDelta(0.3, (float) $preview['preview']['items_total'], 0.001);

        $sample = json_decode((string) file_get_contents(base_path('../docs/api-samples/shipping-options.json')), true);
        $this->assertSame(array_keys($sample['data']), array_keys($preview));
        $this->assertSame(array_keys($sample['data']['preview']), array_keys($preview['preview']));
        $this->assertSame(array_keys($sample['data']['options'][0]), array_keys($preview['options'][0]));

        $order = $this->withToken($token)->postJson('/api/v1/store/checkout', [
            'city_id' => $this->city->id,
            'shipping_address_line' => 'شارع',
            'payment_method' => 'wallet',
            'expected_total' => $preview['preview']['grand_total'],
        ])->assertCreated();
        $this->assertEqualsWithDelta(
            (float) $preview['preview']['grand_total'],
            (float) $order->json('data.grand_total'),
            0.001,
        );

        ShippingRate::query()->delete();
        ShippingZone::query()->delete();
        $cart = Cart::query()->where('customer_id', $this->customer->id)->firstOrFail();
        $cart->items()->create(['product_variant_id' => $this->variant->id, 'qty' => 1]);
        $fallback = $this->withToken($token)->postJson('/api/v1/store/checkout/shipping-options', [
            'city_id' => $this->city->id,
        ])->assertOk()->json('data.options.0.estimated_days');
        $this->assertNull($fallback);
    }

    public function test_repeated_shipping_options_hit_the_checkout_throttle(): void
    {
        $payload = ['city_id' => $this->city->id];
        $last = null;
        for ($i = 0; $i < 31; $i++) {
            $last = $this->postJson('/api/v1/store/checkout/shipping-options', $payload);
        }
        $last->assertStatus(429);
    }

    public function test_store_cities_lists_active_cities_in_arabic_name_order(): void
    {
        City::query()->delete();
        City::factory()->create(['name_ar' => 'ممم', 'name_en' => 'M', 'shipping_cost' => 10, 'is_active' => true]);
        City::factory()->create(['name_ar' => 'ألف', 'name_en' => 'A', 'shipping_cost' => 20, 'is_active' => true]);
        City::factory()->create(['name_ar' => 'مخفية', 'name_en' => 'Hidden', 'shipping_cost' => 5, 'is_active' => false]);

        $rows = $this->getJson('/api/v1/store/cities')->assertOk()->json('data');
        $this->assertCount(2, $rows);
        $this->assertSame(['ألف', 'ممم'], array_column($rows, 'name_ar'));
        $this->assertSame(['id', 'name_ar', 'name_en', 'shipping_cost'], array_keys($rows[0]));
        $sample = json_decode((string) file_get_contents(base_path('../docs/api-samples/cities.json')), true);
        $this->assertSame(array_keys($sample['data'][0]), array_keys($rows[0]));
    }

    private function tax(bool $enabled, bool $included, string $rate): void
    {
        Setting::query()->where('key', 'tax_enabled')->update(['value' => $enabled ? '1' : '0']);
        Setting::query()->where('key', 'prices_include_tax')->update(['value' => $included ? '1' : '0']);
        Setting::query()->where('key', 'default_tax_rate')->update(['value' => $rate]);
        app(SettingService::class)->forgetMemoryState();
    }
}
