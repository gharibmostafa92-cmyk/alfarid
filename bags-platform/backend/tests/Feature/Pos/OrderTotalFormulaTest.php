<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Order;
use App\Services\Orders\OrderService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * F-09: every order write path must store components whose rounded values
 * satisfy orders_total_formula exactly — no 0.01 drift from unrounded math.
 */
class OrderTotalFormulaTest extends PosScenarioFixture
{
    use LazilyRefreshDatabase;

    public function test_pos_sale_with_fractional_prices_and_discount_satisfies_formula(): void
    {
        $variant = $this->makeVariant(10.99);
        $this->stockIn($this->branch->id, $variant, 10);

        // 3 × 10.99 = 32.97, discount 2.22 → grand 30.75; cash must match.
        $token = $this->tokenFor($this->cashier, ['pos.discount']);
        $this->withToken($token)->withHeaders($this->idem())->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $variant->id, 'qty' => 3, 'discount_amount' => 2.22]],
            'payment_method' => 'cash',
            'cash_amount' => 30.75,
            'card_amount' => 0,
        ])->assertStatus(201);

        $order = Order::query()->latest('id')->first();
        $this->assertFormulaHolds($order);
        $this->assertSame(30.75, (float) $order->grand_total);
    }

    public function test_store_checkout_with_percentage_coupon_satisfies_formula(): void
    {
        $coupon = Coupon::factory()->create(['type' => 'percentage', 'value' => 33]);
        $variant = $this->makeVariant(19.99);
        $this->stockIn($this->branch->id, $variant, 10);

        $cart = Cart::create([]);
        $cart->items()->create(['product_variant_id' => $variant->id, 'qty' => 3]);

        $order = app(OrderService::class)->createFromCart($cart, [
            'recipient_name' => 'عميل',
            'recipient_phone' => '01012345678',
            'payment_method' => 'cod',
            'coupon_code' => $coupon->code,
            'legal_policy_version' => \App\Support\StorefrontLegalPolicyVersion::CURRENT,
        ]);

        // 3 × 19.99 = 59.97, 33% = 19.7901 → 19.79 discount.
        $this->assertFormulaHolds($order->fresh());
    }

    #[TestWith(['99.99', '0.00'])]
    #[TestWith(['100.01', '-0.01'])]
    public function test_database_rejects_invalid_pos_tenders(string $cash, string $card): void
    {
        $order = Order::factory()->create(['channel' => 'pos', 'subtotal' => 100, 'grand_total' => 100]);

        try {
            DB::transaction(fn () => $order->update(['cash_amount' => $cash, 'card_amount' => $card]));
            $this->fail('Expected the POS tender constraint to reject the write.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
            $this->assertStringContainsString('orders_pos_tender_total', $e->getMessage());
        }
    }

    private function assertFormulaHolds(Order $order): void
    {
        $expected = round(
            ((float) $order->subtotal - (float) $order->discount_amount)
            + (float) $order->shipping_cost + (float) $order->tax_amount + (float) $order->cod_fee,
            2
        );

        $this->assertSame($expected, round((float) $order->grand_total, 2));
    }
}
