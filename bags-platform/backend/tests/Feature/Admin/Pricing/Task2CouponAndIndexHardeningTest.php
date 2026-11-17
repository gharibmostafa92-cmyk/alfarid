<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Pricing;

use App\Exceptions\BusinessException;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\Pricing\CouponService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class Task2CouponAndIndexHardeningTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function adminUser(): User
    {
        $user = User::factory()->create();
        foreach (['coupons.view', 'coupons.create', 'coupons.update', 'coupons.delete'] as $perm) {
            Permission::findOrCreate($perm, 'staff');
        }
        $user->givePermissionTo(['coupons.view', 'coupons.create', 'coupons.update', 'coupons.delete']);

        return $user;
    }

    public function test_coupon_usages_foreign_key_is_restrict_not_cascade(): void
    {
        $fk = DB::selectOne(<<<'SQL'
            SELECT confdeltype
            FROM pg_constraint
            WHERE conname = 'coupon_usages_coupon_id_foreign'
        SQL);

        $this->assertNotNull($fk);
        // 'r' = RESTRICT, 'c' = CASCADE
        $this->assertSame('r', $fk->confdeltype);
    }

    public function test_record_usage_rejects_anonymous_redemption(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'ANON10', 'usage_limit_per_customer' => 0]);
        $order = Order::factory()->create(['customer_id' => null, 'guest_phone' => null]);

        $this->expectException(BusinessException::class);
        app(CouponService::class)->recordUsage($coupon, $order, null, 10.0);
    }

    public function test_record_usage_acquires_sorted_advisory_locks_for_customer_and_phone(): void
    {
        $customer = Customer::factory()->create(['phone' => '+201012345678']);
        $coupon = Coupon::factory()->create(['code' => 'LOCKDUAL', 'usage_limit_per_customer' => 1]);
        $order = Order::factory()->create(['customer_id' => $customer->id]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        DB::transaction(function () use ($coupon, $order, $customer): void {
            app(CouponService::class)->recordUsage($coupon, $order, $customer, 15.0);
        });

        $lockBindings = collect(DB::getQueryLog())
            ->filter(fn (array $q) => str_contains($q['query'], 'pg_advisory_xact_lock'))
            ->flatMap(fn (array $q) => $q['bindings'] ?? [])
            ->values()
            ->all();

        DB::disableQueryLog();

        $expected = [
            "coupon-usage:{$coupon->id}:customer:{$customer->id}",
            "coupon-usage:{$coupon->id}:phone:".Customer::normalizePhone('+201012345678'),
        ];
        sort($expected);

        $this->assertSame($expected, $lockBindings);
    }

    public function test_coupon_update_rejects_usage_limit_below_used_count(): void
    {
        $user = $this->adminUser();
        $token = $user->createToken('test')->plainTextToken;
        $coupon = Coupon::factory()->create([
            'code' => 'USED5',
            'type' => 'fixed',
            'value' => 20,
            'usage_limit' => 10,
            'used_count' => 5,
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->putJson("/api/v1/admin/coupons/{$coupon->id}", [
                'code' => 'USED5',
                'type' => 'fixed',
                'value' => 20,
                'usage_limit' => 3,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('usage_limit');
    }

    public function test_products_occasions_gin_index_exists_and_is_valid(): void
    {
        $idx = DB::selectOne(<<<'SQL'
            SELECT i.indisvalid, am.amname
            FROM pg_class c
            JOIN pg_index i ON i.indexrelid = c.oid
            JOIN pg_am am ON am.oid = c.relam
            WHERE c.relnamespace = current_schema()::regnamespace
              AND c.relname = 'products_occasions_gin'
        SQL);

        $this->assertNotNull($idx);
        $this->assertTrue((bool) $idx->indisvalid);
        $this->assertSame('gin', $idx->amname);
    }
}
