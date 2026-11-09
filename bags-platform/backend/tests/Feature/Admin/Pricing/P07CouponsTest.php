<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Pricing;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\Pricing\CouponService;
use App\Services\Orders\OrderService;
use Database\Seeders\PricingPermissionSeeder;
use Database\Seeders\PromotionPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * task.txt items 8b/8c/8d, 9, 27, 28 plus section-3 coupon edges.
 * Global-limit races live in P07ParallelRaceTest (separate processes).
 */
class P07CouponsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const NOT_REDEEMABLE = 'كود الخصم غير صحيح أو غير متاح أو انتهت صلاحيته أو استُنفد حد استخدامه.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(PricingPermissionSeeder::class);
        $this->seed(PromotionPermissionSeeder::class);
        config(['store.coupon_apply_per_minute' => 20]);
    }

    private function staff(array $perms): User
    {
        $u = User::factory()->create();
        foreach ($perms as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }

        return $u;
    }

    private function couponAdmin(): User
    {
        return $this->staff(['coupons.view', 'coupons.create', 'coupons.update', 'coupons.delete']);
    }

    private function token(User $u): string
    {
        Auth::forgetGuards();

        return $u->createToken('test')->plainTextToken;
    }

    private function customer(): Customer
    {
        return Customer::factory()->create(['type' => CustomerType::Retail, 'status' => CustomerStatus::Approved]);
    }

    private function order(array $overrides = []): Order
    {
        return Order::factory()->create($overrides);
    }

    // ── item 8b: 010… and +20 101… are the same guest identity ──
    public function test_guest_phone_variants_share_one_allowance(): void
    {
        $coupon = Coupon::factory()->create(['usage_limit' => null, 'usage_limit_per_customer' => 1]);
        $svc = app(CouponService::class);

        $svc->recordUsage($coupon, $this->order(['guest_phone' => '01012345678', 'customer_id' => null]), null, 10.0);

        try {
            $svc->recordUsage($coupon, $this->order(['guest_phone' => '+20 101 234 5678', 'customer_id' => null]), null, 10.0);
            $this->fail('expected NOT_REDEEMABLE for the same guest number in another format');
        } catch (BusinessException $e) {
            $this->assertSame(self::NOT_REDEEMABLE, $e->getMessage());
        }
        $this->assertSame(1, CouponUsage::count());
    }

    // ── item 8c: guest without a phone has no per-customer cap (owner decision) ──
    public function test_guest_without_phone_has_no_per_identity_cap(): void
    {
        $coupon = Coupon::factory()->create(['usage_limit' => null, 'usage_limit_per_customer' => 1]);
        $svc = app(CouponService::class);

        $svc->recordUsage($coupon, $this->order(['guest_phone' => null, 'customer_id' => null]), null, 5.0);
        // Current behavior: anonymous guests bypass the per-customer limit.
        $svc->recordUsage($coupon, $this->order(['guest_phone' => null, 'customer_id' => null]), null, 5.0);

        $this->assertSame(2, CouponUsage::count());
    }

    // ── item 8d: cancelling restores the customer allowance ──
    public function test_cancelled_order_restores_customer_allowance(): void
    {
        $coupon = Coupon::factory()->create(['usage_limit' => null, 'usage_limit_per_customer' => 1]);
        $customer = $this->customer();
        $svc = app(CouponService::class);

        $order = $this->order(['customer_id' => $customer->id, 'guest_phone' => null]);
        $svc->recordUsage($coupon, $order, $customer, 10.0);
        $this->assertSame(1, (int) $coupon->fresh()->used_count);

        app(OrderService::class)->changeStatus($order, OrderStatus::Cancelled, $this->couponAdmin());

        $this->assertSame(0, (int) $coupon->fresh()->used_count);
        // Allowance regained: a new order redeems fine.
        $svc->recordUsage($coupon, $this->order(['customer_id' => $customer->id, 'guest_phone' => null]), $customer, 10.0);
        $this->assertSame(1, (int) $coupon->fresh()->used_count);
    }

    // ── item 9: cancelling restores the GLOBAL quota too ──
    public function test_cancelled_order_restores_global_quota(): void
    {
        $coupon = Coupon::factory()->create(['usage_limit' => 1, 'usage_limit_per_customer' => 5]);
        $svc = app(CouponService::class);

        $order = $this->order(['guest_phone' => '01000000001']);
        $svc->recordUsage($coupon, $order, null, 10.0);
        $this->assertSame(1, (int) $coupon->fresh()->used_count);

        try {
            $svc->recordUsage($coupon, $this->order(['guest_phone' => '01000000002']), null, 10.0);
            $this->fail('expected NOT_REDEEMABLE while the single global slot is taken');
        } catch (BusinessException $e) {
            $this->assertSame(self::NOT_REDEEMABLE, $e->getMessage());
        }

        app(OrderService::class)->changeStatus($order, OrderStatus::Cancelled, $this->couponAdmin());

        $this->assertSame(0, (int) $coupon->fresh()->used_count);
        $this->assertSame(0, CouponUsage::count());
        $svc->recordUsage($coupon, $this->order(['guest_phone' => '01000000002']), null, 10.0);
        $this->assertSame(1, (int) $coupon->fresh()->used_count);
    }

    // ── item 10 (sequential): deleting a used coupon is refused, never 500 ──
    public function test_destroy_with_recorded_usage_is_refused(): void
    {
        $coupon = Coupon::factory()->create(['usage_limit' => null, 'usage_limit_per_customer' => 5]);
        app(CouponService::class)->recordUsage($coupon, $this->order(['guest_phone' => '01000000003']), null, 4.0);

        $this->withToken($this->token($this->couponAdmin()))
            ->deleteJson("/api/v1/admin/coupons/{$coupon->id}")
            ->assertStatus(422);
        $this->assertNotNull(Coupon::find($coupon->id));
        $this->assertSame(1, CouponUsage::count());
    }

    // ── section 3: deleting a coupon detaches pending carts ──
    public function test_destroy_detaches_pending_carts(): void
    {
        $coupon = Coupon::factory()->create(['usage_limit' => null, 'usage_limit_per_customer' => 5]);
        $cart = Cart::factory()->create(['coupon_id' => $coupon->id]);

        $this->withToken($this->token($this->couponAdmin()))
            ->deleteJson("/api/v1/admin/coupons/{$coupon->id}")
            ->assertOk();
        $this->assertNull($cart->fresh()->coupon_id);
        $this->assertNull(Coupon::find($coupon->id));
    }

    private function validateError(string $code, float $subtotal, ?Customer $customer = null): string
    {
        $cart = Cart::factory()->create();
        try {
            app(CouponService::class)->validateAndApply($cart, $code, $subtotal, $customer);
        } catch (BusinessException $e) {
            return $e->getMessage();
        }
        $this->fail("expected BusinessException for code {$code}");

        return '';
    }

    // ── item 27: one uniform message for every rejection ──
    public function test_all_rejections_share_one_message(): void
    {
        $expired = Coupon::factory()->create(['code' => 'EXP01', 'usage_limit' => null, 'usage_limit_per_customer' => 5, 'ends_at' => now()->subDay()]);
        $exhausted = Coupon::factory()->create(['code' => 'EXH01', 'usage_limit' => 1, 'used_count' => 1, 'usage_limit_per_customer' => 5]);
        $minOrder = Coupon::factory()->create(['code' => 'MIN01', 'usage_limit' => null, 'usage_limit_per_customer' => 5, 'min_order_amount' => 500]);

        $messages = [
            $this->validateError('NOPE-DNE', 100.0),
            $this->validateError('EXP01', 100.0),
            $this->validateError('EXH01', 100.0),
            $this->validateError('MIN01', 100.0),
        ];
        foreach ($messages as $message) {
            $this->assertSame(self::NOT_REDEEMABLE, $message);
        }
        $this->assertSame($expired->code, 'EXP01');
    }

    // ── item 27: the 21st per-coupon attempt is rate-limited ──
    public function test_twenty_first_apply_attempt_returns_429(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'RATE01', 'usage_limit' => null, 'usage_limit_per_customer' => 100]);
        $svc = app(CouponService::class);

        for ($i = 0; $i < 20; $i++) {
            $svc->validateAndApply(Cart::factory()->create(), 'RATE01', 100.0);
        }
        try {
            $svc->validateAndApply(Cart::factory()->create(), 'RATE01', 100.0);
            $this->fail('expected 429 on the 21st attempt');
        } catch (BusinessException $e) {
            $this->assertSame(429, $e->status);
            $this->assertSame(self::NOT_REDEEMABLE, $e->getMessage());
        }
    }

    // ── item 27: the store coupon route throttles at 10/minute ──
    public function test_store_coupon_route_throttles_repeated_guesses(): void
    {
        $cart = Cart::factory()->create();
        $last = null;
        for ($i = 0; $i < 11; $i++) {
            $last = $this->withHeaders(['X-Cart-Session' => $cart->cart_token])
                ->postJson('/api/v1/store/cart/coupon', ['code' => 'GUESS']);
        }
        $this->assertSame(429, $last->getStatusCode());
    }

    private function couponPayload(array $overrides = []): array
    {
        return array_merge(['code' => 'C'.strtoupper(uniqid()), 'type' => 'percentage', 'value' => 10], $overrides);
    }

    private function postCoupon(User $admin, array $payload): TestResponse
    {
        return $this->withToken($this->token($admin))->postJson('/api/v1/admin/coupons', $payload);
    }

    // ── item 28: case-insensitive duplicate codes are rejected ──
    public function test_duplicate_code_case_insensitive_returns_422(): void
    {
        $admin = $this->couponAdmin();
        $this->postCoupon($admin, $this->couponPayload(['code' => 'SAVE10']))->assertCreated();
        $this->postCoupon($admin, $this->couponPayload(['code' => 'save10']))->assertStatus(422);
    }

    // ── item 28: sequential duplicate insert returns 422, never 500 ──
    public function test_sequential_duplicate_insert_returns_422(): void
    {
        $admin = $this->couponAdmin();
        $payload = $this->couponPayload(['code' => 'DUPSEQ']);
        $this->postCoupon($admin, $payload)->assertCreated();
        // Validation catches the duplicate before the insert.
        $this->postCoupon($admin, $payload)->assertStatus(422);
    }

    // ── item 28: percentage over 100 is rejected on create and type switch ──
    public function test_percentage_over_100_is_rejected(): void
    {
        $admin = $this->couponAdmin();
        $this->postCoupon($admin, $this->couponPayload(['value' => 101]))->assertStatus(422);

        $fixed = $this->postCoupon($admin, $this->couponPayload(['type' => 'fixed', 'value' => 150]))->assertCreated()->json('data');
        $this->withToken($this->token($admin))->putJson("/api/v1/admin/coupons/{$fixed['id']}",
            ['code' => $fixed['code'], 'type' => 'percentage', 'value' => 150])->assertStatus(422);
    }

    // ── item 28: usage_limit_per_customer=0 means unlimited (owner decision) ──
    public function test_zero_per_customer_limit_means_unlimited(): void
    {
        $admin = $this->couponAdmin();
        $created = $this->postCoupon($admin, $this->couponPayload(['usage_limit_per_customer' => 0]))->assertCreated()->json('data');
        $this->assertSame(0, $created['usage_limit_per_customer']);

        $coupon = Coupon::findOrFail($created['id']);
        $svc = app(CouponService::class);
        $customer = $this->customer();
        $svc->recordUsage($coupon, $this->order(['customer_id' => $customer->id, 'guest_phone' => null]), $customer, 5.0);
        $svc->recordUsage($coupon, $this->order(['customer_id' => $customer->id, 'guest_phone' => null]), $customer, 5.0);
        $this->assertSame(2, CouponUsage::where('coupon_id', $coupon->id)->count());
    }

    // ── item 28: partial PUT is rejected (code+type required; owner decision) ──
    public function test_partial_update_without_code_and_type_is_rejected(): void
    {
        $admin = $this->couponAdmin();
        $created = $this->postCoupon($admin, $this->couponPayload())->assertCreated()->json('data');

        $this->withToken($this->token($admin))->putJson("/api/v1/admin/coupons/{$created['id']}", ['is_active' => false])->assertStatus(422);
    }

    // ── section 3: lowering the limit below usage is allowed and voids the coupon ──
    public function test_lowering_limit_below_usage_is_allowed_and_voids(): void
    {
        $admin = $this->couponAdmin();
        $created = $this->postCoupon($admin, $this->couponPayload(['code' => 'LOWLIM', 'usage_limit' => 5, 'usage_limit_per_customer' => 5]))->assertCreated()->json('data');
        $coupon = Coupon::findOrFail($created['id']);
        app(CouponService::class)->recordUsage($coupon, $this->order(['guest_phone' => '01000000009']), null, 5.0);

        $this->withToken($this->token($admin))->putJson("/api/v1/admin/coupons/{$coupon->id}",
            ['code' => 'LOWLIM', 'type' => 'percentage', 'value' => 10, 'usage_limit' => 1])->assertOk();
        $this->assertFalse($coupon->fresh()->isRedeemableNow());
        $this->assertSame(self::NOT_REDEEMABLE, $this->validateError('LOWLIM', 100.0));
    }

    // ── section 3: future, inactive, and exhausted coupons are rejected ──
    public function test_future_inactive_and_exhausted_coupons_rejected(): void
    {
        Coupon::factory()->create(['code' => 'FUT01', 'usage_limit' => null, 'usage_limit_per_customer' => 5, 'starts_at' => now()->addDay()]);
        Coupon::factory()->create(['code' => 'INA01', 'usage_limit' => null, 'usage_limit_per_customer' => 5, 'is_active' => false]);
        Coupon::factory()->create(['code' => 'USE01', 'usage_limit' => 2, 'used_count' => 2, 'usage_limit_per_customer' => 5]);

        $this->assertSame(self::NOT_REDEEMABLE, $this->validateError('FUT01', 100.0));
        $this->assertSame(self::NOT_REDEEMABLE, $this->validateError('INA01', 100.0));
        $this->assertSame(self::NOT_REDEEMABLE, $this->validateError('USE01', 100.0));
    }

    public function test_coupon_accepts_exact_schedule_and_subtotal_boundaries(): void
    {
        $this->freezeSecond();
        $starts = now()->addSecond();
        $ends = now()->addMinute();
        $coupon = Coupon::factory()->create([
            'code' => 'BOUNDARY', 'starts_at' => $starts, 'ends_at' => $ends,
            'min_order_amount' => 100, 'usage_limit' => null,
        ]);
        $service = app(CouponService::class);
        $this->assertSame(self::NOT_REDEEMABLE, $this->validateError('BOUNDARY', 100.0));

        $this->travelTo($starts);
        $this->assertSame(self::NOT_REDEEMABLE, $this->validateError('BOUNDARY', 99.99));
        $this->assertSame($coupon->id, $service->redeemableById($coupon->id, 100.0)->id);

        $this->travelTo($ends);
        $this->assertSame($coupon->id, $service->redeemableById($coupon->id, 100.0)->id);
        $this->travel(1)->seconds();
        $this->assertSame(self::NOT_REDEEMABLE, $this->validateError('BOUNDARY', 100.0));
    }

    // ── section 3: checkout revalidation path honors exhaustion ──
    public function test_checkout_revalidation_rejects_exhausted_coupon(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'CHK01', 'usage_limit' => 1, 'used_count' => 1, 'usage_limit_per_customer' => 5]);

        try {
            app(CouponService::class)->redeemableById($coupon->id, 100.0);
            $this->fail('expected NOT_REDEEMABLE');
        } catch (BusinessException $e) {
            $this->assertSame(self::NOT_REDEEMABLE, $e->getMessage());
        }
        $this->assertSame(self::NOT_REDEEMABLE, $this->validateError('CHK01', 100.0));
    }

    // ── section 3: cart coupon apply/remove roundtrip ──
    public function test_cart_coupon_apply_and_remove(): void
    {
        Coupon::factory()->create(['code' => 'CART10', 'usage_limit' => null, 'usage_limit_per_customer' => 5]);
        $cart = Cart::factory()->create();

        $applied = $this->withHeaders(['X-Cart-Session' => $cart->cart_token])
            ->postJson('/api/v1/store/cart/coupon', ['code' => '  cart10  ']);
        $applied->assertOk();
        $this->assertSame(Coupon::where('code', 'CART10')->firstOrFail()->id, (int) $cart->fresh()->coupon_id);

        $this->withHeaders(['X-Cart-Session' => $cart->cart_token])->deleteJson('/api/v1/store/cart/coupon')->assertOk();
        $this->assertNull($cart->fresh()->coupon_id);
    }
}
