<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\City;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\CartService;
use App\Services\CouponService;
use App\Services\InventoryService;
use App\Services\OrderAllocationService;
use App\Services\OrderService;
use App\Services\SettingService;
use App\Services\ShippingQuoteService;
use Database\Seeders\SettingSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreCriticalAssuranceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->staff = User::factory()->create();
        $this->branch = Branch::factory()->create();
    }

    public function test_recent_guest_cart_activity_survives_prune(): void
    {
        $variant = $this->purchasableVariant(80);
        $this->giveStock($variant, 5);
        $cart = Cart::query()->create([]);
        $cart->forceFill([
            'created_at' => now()->subDays(8),
            'updated_at' => now()->subDays(8),
        ])->saveQuietly();

        $this->withHeader('X-Cart-Session', $cart->cart_token)
            ->postJson('/api/v1/store/cart/items', [
                'product_variant_id' => $variant->id,
                'qty' => 1,
            ])->assertOk();

        $this->artisan('carts:prune-guests', ['--days' => 7])->assertSuccessful();

        $this->assertModelExists($cart);
        $this->assertSame(1, CartItem::query()->where('cart_id', $cart->id)->count());
        $this->assertTrue($cart->fresh()->updated_at->greaterThan(now()->subDay()));
    }

    public function test_prune_treats_zero_days_as_one_and_never_deletes_customer_carts(): void
    {
        $customer = Customer::factory()->create();
        $recent = Cart::query()->create([]);
        $recent->forceFill(['updated_at' => now()->subHours(12)])->saveQuietly();
        $stale = Cart::query()->create([]);
        $stale->forceFill(['updated_at' => now()->subDays(2)])->saveQuietly();
        $account = Cart::query()->create(['customer_id' => $customer->id]);
        $account->forceFill(['updated_at' => now()->subDays(30)])->saveQuietly();

        $this->artisan('carts:prune-guests', ['--days' => 0])->assertSuccessful();

        $this->assertModelExists($recent);
        $this->assertModelMissing($stale);
        $this->assertModelExists($account);
    }

    public function test_prune_deletes_guest_carts_in_batches_and_cascades_items(): void
    {
        $variant = $this->purchasableVariant(10);
        $old = now()->subDays(30);
        $rows = [];
        for ($i = 0; $i < 1001; $i++) {
            $rows[] = [
                'cart_token' => (string) Str::uuid(),
                'customer_id' => null,
                'coupon_id' => null,
                'created_at' => $old,
                'updated_at' => $old,
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            Cart::query()->insert($chunk);
        }
        $doomed = Cart::query()->whereNull('customer_id')->firstOrFail();
        CartItem::query()->create([
            'cart_id' => $doomed->id,
            'product_variant_id' => $variant->id,
            'qty' => 1,
        ]);
        $customerCart = Cart::query()->create(['customer_id' => Customer::factory()->create()->id]);
        $customerCart->forceFill(['updated_at' => $old])->saveQuietly();

        $this->artisan('carts:prune-guests')->assertSuccessful();

        $this->assertSame(0, Cart::query()->whereNull('customer_id')->count());
        $this->assertSame(0, CartItem::query()->count());
        $this->assertModelExists($customerCart);
    }

    public function test_same_idempotency_key_with_different_address_returns_409(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 5);
        $city = City::factory()->create(['shipping_cost' => 0]);
        $otherCity = City::factory()->create(['shipping_cost' => 0]);
        $added = $this->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id,
            'qty' => 1,
        ])->assertOk();
        $headers = [
            'X-Cart-Session' => $added->json('data.cart_token'),
            'Idempotency-Key' => 'checkout-key-k',
        ];
        $payload = [
            'recipient_name' => 'ضيف',
            'recipient_phone' => '01012345678',
            'city_id' => $city->id,
            'shipping_address_line' => 'العنوان الأول',
            'payment_method' => 'cod',
            'expected_total' => 100,
        ];

        $first = $this->withHeaders($headers)->postJson('/api/v1/store/checkout', $payload)->assertCreated();

        $this->withHeaders($headers)->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id,
            'qty' => 1,
        ])->assertOk();
        $conflict = $this->withHeaders($headers)->postJson('/api/v1/store/checkout', [
            ...$payload,
            'city_id' => $otherCity->id,
            'shipping_address_line' => 'عنوان جديد',
            'expected_total' => 200,
        ]);

        $conflict->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->assertSame(1, Order::query()->count());
        $this->assertSame($first->json('data.order_number'), Order::query()->sole()->order_number);
    }

    public function test_store_caps_each_variant_at_the_quantity_one_branch_can_fulfill(): void
    {
        $variant = $this->purchasableVariant(100);
        $other = Branch::factory()->create();
        $shelf = Location::factory()->for($this->branch)->create();
        $receiving = Location::factory()->for($other)->create(['type' => 'receiving']);
        $this->giveStock($variant, 1);
        $this->giveStock($variant, 2, $this->branch, $shelf);
        $this->giveStock($variant, 2, $other);
        $this->giveStock($variant, 10, $other, $receiving);
        $city = City::factory()->create(['shipping_cost' => 0]);

        $this->getJson('/api/v1/store/products/'.$variant->product->slug)
            ->assertOk()->assertJsonPath('data.variants.0.max_qty', 3);
        $this->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id,
            'qty' => 5,
        ])->assertUnprocessable();
        $added = $this->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id,
            'qty' => 3,
        ])->assertOk()->assertJsonPath('data.items.0.max_qty', 3);

        $this->withHeader('X-Cart-Session', $added->json('data.cart_token'));
        $item = CartItem::query()->sole();
        $this->putJson('/api/v1/store/cart/items/'.$item->id, ['qty' => 5])->assertUnprocessable();
        $this->assertSame(3.0, (float) $item->fresh()->qty);
        $payload = [
            'recipient_name' => 'ضيف',
            'recipient_phone' => '01012345678',
            'city_id' => $city->id,
            'shipping_address_line' => 'عنوان',
            'payment_method' => 'cod',
        ];
        $item->update(['qty' => 5]); // A cart saved under the previous availability rule.
        $this->getJson('/api/v1/store/cart/items')->assertOk()->assertJsonPath('data.items.0.max_qty', 3);
        $this->postJson('/api/v1/store/checkout', $payload)->assertUnprocessable();

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0.0, (float) StockLevel::query()->sum('reserved'));

        $this->putJson('/api/v1/store/cart/items/'.$item->id, ['qty' => 3])->assertOk();
        $this->postJson('/api/v1/store/checkout', [...$payload, 'expected_total' => 300])->assertCreated();
        $this->assertSame($this->branch->id, Order::query()->sole()->allocated_branch_id);
        $this->assertSame(3.0, (float) StockLevel::query()->sum('reserved'));
    }

    public function test_expire_pending_does_not_cancel_an_order_confirmed_before_the_transition(): void
    {
        $order = Order::factory()->create([
            'status' => OrderStatus::Pending,
            'payment_method' => 'cod',
            'customer_id' => null,
            'created_at' => now()->subHours(13),
        ]);
        $this->app->bind(OrderService::class, fn ($app): OrderService => new ConfirmBeforeCancelOrderService(
            $app->make(OrderAllocationService::class),
            $app->make(CartService::class),
            $app->make(CouponService::class),
            $app->make(SettingService::class),
            $app->make(InventoryService::class),
            $app->make(ShippingQuoteService::class),
        ));

        $this->artisan('orders:expire-pending')->assertSuccessful();

        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertSame(0, $order->statusHistories()->count());
    }

    public function test_coupon_disabled_after_apply_returns_coupon_invalid_and_creates_no_order(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 5);
        Coupon::factory()->create(['code' => 'SAVE10', 'type' => 'fixed', 'value' => 10]);
        $city = City::factory()->create(['shipping_cost' => 0]);
        $token = $this->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id,
            'qty' => 1,
        ])->assertOk()->json('data.cart_token');
        $this->withHeader('X-Cart-Session', $token)
            ->postJson('/api/v1/store/cart/coupon', ['code' => 'save10'])->assertOk();
        Coupon::query()->sole()->update(['is_active' => false]);

        $this->withHeader('X-Cart-Session', $token)->postJson('/api/v1/store/checkout', [
            'recipient_name' => 'ضيف',
            'recipient_phone' => '01012345678',
            'city_id' => $city->id,
            'shipping_address_line' => 'عنوان',
            'payment_method' => 'cod',
        ])->assertUnprocessable()->assertJsonPath('code', 'coupon_invalid');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Coupon::query()->sole()->used_count);
    }

    public function test_coupon_usage_race_inside_checkout_returns_coupon_invalid_and_rolls_back(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 5);
        $coupon = Coupon::factory()->create([
            'code' => 'ONCE',
            'type' => 'fixed',
            'value' => 10,
            'usage_limit' => 1,
        ]);
        $cart = Cart::query()->create(['coupon_id' => $coupon->id]);
        $cart->items()->create(['product_variant_id' => $variant->id, 'qty' => 1]);
        $coupons = \Mockery::mock(app(CouponService::class))->makePartial();
        $coupons->shouldReceive('redeemableById')->andReturn($coupon);
        $coupons->shouldReceive('enforceRateLimit');
        $coupons->shouldReceive('discountFor')->andReturn(10.0);
        $coupons->shouldReceive('recordUsage')->once()->andThrow(new BusinessException('كود الخصم غير صحيح أو غير متاح أو انتهت صلاحيته أو استُنفد حد استخدامه.'));
        $this->app->instance(CouponService::class, $coupons);

        try {
            app(OrderService::class)->createFromCart($cart, [
                'recipient_name' => 'ضيف',
                'recipient_phone' => '01012345678',
                'payment_method' => 'cod',
            ]);
            $this->fail('استنفاد الكوبون أثناء إنشاء الطلب كان يجب أن يفشل.');
        } catch (BusinessException $exception) {
            $this->assertSame('coupon_invalid', $exception->errorCode);
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0.0, (float) StockLevel::query()->sum('reserved'));
    }

    public function test_customer_cannot_reuse_a_coupon_as_a_guest_with_the_same_phone(): void
    {
        $customer = Customer::factory()->create(['phone' => '01055556666']);
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 5);
        Coupon::factory()->create([
            'code' => 'ONCE',
            'type' => 'fixed',
            'value' => 10,
            'usage_limit_per_customer' => 1,
        ]);
        $city = City::factory()->create(['shipping_cost' => 0]);
        $token = $customer->createToken('store')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id,
            'qty' => 1,
        ])->assertOk();
        $this->withToken($token)->postJson('/api/v1/store/cart/coupon', ['code' => 'ONCE'])->assertOk();
        $this->withToken($token)->postJson('/api/v1/store/checkout', [
            'recipient_name' => $customer->name,
            'recipient_phone' => '01055556666',
            'city_id' => $city->id,
            'shipping_address_line' => 'عنوان الحساب',
            'payment_method' => 'cod',
            'expected_total' => 90,
        ])->assertCreated();

        Auth::forgetGuards();
        $this->flushHeaders();
        $guest = $this->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id,
            'qty' => 1,
        ])->assertOk();
        $guestToken = $guest->json('data.cart_token');
        $this->flushHeaders();
        $this->withHeader('X-Cart-Session', $guestToken)
            ->postJson('/api/v1/store/cart/coupon', ['code' => 'ONCE'])->assertOk();
        $this->withHeader('X-Cart-Session', $guestToken)->postJson('/api/v1/store/checkout', [
            'recipient_name' => 'زائر',
            'recipient_phone' => '01055556666',
            'city_id' => $city->id,
            'shipping_address_line' => 'عنوان الزائر',
            'payment_method' => 'cod',
            'expected_total' => 90,
        ])->assertUnprocessable()->assertJsonPath('code', 'coupon_invalid');

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Coupon::query()->sole()->used_count);
    }

    public function test_guest_cannot_change_another_guests_cart_line(): void
    {
        $variant = $this->purchasableVariant(50);
        $this->giveStock($variant, 5);
        $owner = $this->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id,
            'qty' => 2,
        ])->assertOk();
        $itemId = $owner->json('data.items.0.id');
        $intruder = $this->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id,
            'qty' => 1,
        ])->assertOk()->json('data.cart_token');

        $this->withHeader('X-Cart-Session', $intruder)
            ->putJson("/api/v1/store/cart/items/{$itemId}", ['qty' => 1])
            ->assertNotFound();
        $this->withHeader('X-Cart-Session', $intruder)
            ->deleteJson("/api/v1/store/cart/items/{$itemId}")
            ->assertNoContent();

        $this->assertSame(2, (int) CartItem::query()->findOrFail($itemId)->qty);
    }

    public function test_guest_token_cannot_open_a_customer_cart(): void
    {
        $variant = $this->purchasableVariant(40);
        $this->giveStock($variant, 4);
        $customer = Customer::factory()->create();
        $token = $customer->createToken('store')->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id,
            'qty' => 1,
        ])->assertOk();
        $cartToken = Cart::query()->where('customer_id', $customer->id)->value('cart_token');
        $itemId = CartItem::query()->sole()->id;

        Auth::forgetGuards();
        $this->flushHeaders();
        $this->withHeader('X-Cart-Session', $cartToken)
            ->getJson('/api/v1/store/cart/items')
            ->assertOk()
            ->assertJsonPath('data.items', []);
        $this->withHeader('X-Cart-Session', $cartToken)
            ->putJson("/api/v1/store/cart/items/{$itemId}", ['qty' => 4])
            ->assertNotFound();

        $this->assertSame(1, (int) CartItem::query()->findOrFail($itemId)->qty);
    }

    public function test_customer_cannot_cancel_another_customers_order_and_non_numeric_id_returns_404(): void
    {
        $owner = Customer::factory()->create();
        $other = Customer::factory()->create();
        $order = Order::factory()->create([
            'customer_id' => $owner->id,
            'status' => OrderStatus::Pending,
        ]);

        $this->withToken($other->createToken('store')->plainTextToken)
            ->postJson("/api/v1/store/account/orders/{$order->id}/cancel")
            ->assertNotFound();
        $this->withToken($owner->createToken('store')->plainTextToken)
            ->postJson('/api/v1/store/account/orders/abc/cancel')
            ->assertNotFound();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_disallowed_status_transitions_do_not_write_history(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);
        $allowed = [
            'pending' => ['confirmed', 'cancelled'],
            'confirmed' => ['processing', 'shipped', 'cancelled'],
            'processing' => ['shipped', 'cancelled'],
            'shipped' => ['delivered', 'returned'],
            'delivered' => ['returned'],
            'cancelled' => [],
            'returned' => [],
        ];

        foreach (OrderStatus::cases() as $from) {
            foreach (OrderStatus::cases() as $to) {
                if ($from === $to || in_array($to->value, $allowed[$from->value], true)) {
                    continue;
                }
                $order->forceFill(['status' => $from])->save();

                try {
                    app(OrderService::class)->changeStatus($order->fresh(), $to);
                    $this->fail("{$from->value} -> {$to->value} was accepted");
                } catch (BusinessException $exception) {
                    $this->assertStringContainsString('انتقال حالة غير مسموح', $exception->getMessage());
                }

                $this->assertSame($from, $order->fresh()->status);
                $this->assertSame(0, $order->statusHistories()->count());
            }
        }
    }

    public function test_repeating_the_current_status_writes_no_history(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);

        $updated = app(OrderService::class)->changeStatus($order, OrderStatus::Pending);

        $this->assertSame(OrderStatus::Pending, $updated->status);
        $this->assertSame(0, $updated->statusHistories()->count());
    }

    public function test_allowed_status_transitions_record_actor_and_truncate_multibyte_notes(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 10);
        $service = app(OrderService::class);
        $notes = str_repeat('م', 501);
        $paths = [
            ['cancelled'],
            ['confirmed', 'cancelled'],
            ['confirmed', 'processing', 'cancelled'],
            ['confirmed', 'processing', 'shipped', 'delivered', 'returned'],
            ['confirmed', 'shipped', 'returned'],
        ];
        foreach ($paths as $index => $path) {
            $cart = Cart::query()->create([]);
            $cart->items()->create(['product_variant_id' => $variant->id, 'qty' => 1]);
            $order = $service->createFromCart($cart, [
                'recipient_name' => 'ضيف',
                'recipient_phone' => sprintf('01050000%03d', $index),
                'payment_method' => 'cod',
            ]);
            foreach ($path as $next) {
                $from = $order->status;
                $to = OrderStatus::from($next);
                $order = $service->changeStatus($order, $to, $this->staff, $notes);
                $this->assertSame($to, $order->status);
                $history = $order->statusHistories()->latest('id')->firstOrFail();
                $this->assertSame($from, $history->from_status);
                $this->assertSame($to, $history->to_status);
                $this->assertTrue($history->changedBy->is($this->staff));
                $this->assertSame(str_repeat('م', 500), $history->notes);
                if ($to === OrderStatus::Delivered) {
                    $this->assertSame('paid', $order->payment_status);
                }
            }
            $this->assertSame(1 + count($path), $order->statusHistories()->count());
        }
    }

    public function test_shipping_without_a_reservation_is_rejected(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Confirmed]);

        try {
            app(OrderService::class)->changeStatus($order, OrderStatus::Shipped);
            $this->fail('الشحن بلا حجز كان يجب أن يفشل.');
        } catch (BusinessException $exception) {
            $this->assertStringContainsString('لا توجد حجوزات', $exception->getMessage());
        }

        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertSame(0, $order->statusHistories()->count());
    }

    private function purchasableVariant(float $price): ProductVariant
    {
        $product = Product::factory()->create(['status' => 'published']);

        return ProductVariant::factory()->create([
            'product_id' => $product->id,
            'retail_price' => $price,
        ]);
    }

    private function giveStock(ProductVariant $variant, float|int $qty, ?Branch $branch = null, ?Location $location = null): void
    {
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id,
            'branch_id' => ($branch ?? $this->branch)->id,
            'location_id' => $location?->id,
            'type' => StockMovementType::AdjustmentIn,
            'affected_bucket' => StockBucket::OnHand,
            'quantity' => $qty,
            'created_by' => $this->staff->id,
        ]);
    }
}

final class ConfirmBeforeCancelOrderService extends OrderService
{
    public function changeStatus(
        Order $order,
        OrderStatus|string $toStatus,
        ?Model $actor = null,
        ?string $notes = null,
        ?array $expectedFrom = null,
        bool $returnEffects = true,
        bool $allowNoticesLoss = false,
    ): Order {
        Order::query()->whereKey($order->id)->update(['status' => OrderStatus::Confirmed->value]);

        return parent::changeStatus($order->fresh(), $toStatus, $actor, $notes, $expectedFrom, $returnEffects, $allowNoticesLoss);
    }
}
