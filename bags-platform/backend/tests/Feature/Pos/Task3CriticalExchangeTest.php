<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\Customer\CustomerStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\StockLevel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;

class Task3CriticalExchangeTest extends PosScenarioFixture
{
    use LazilyRefreshDatabase;

    private function as(string $token): static
    {
        Auth::forgetGuards();

        return $this->withToken($token);
    }

    public function test_mixed_paid_swap_consumes_card_first_and_returns_accurate_financial_breakdown(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.sales.refund']);
        $replacement = $this->makeVariant(150.00);
        $this->stockIn($this->branch->id, $replacement, 10);

        // Original sale: 500 total (200 cash, 300 card)
        $sale = $this->as($token)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 5]], // 5 * 100 = 500
            'cash_amount' => 200,
            'card_amount' => 300,
        ], $this->idem())->assertCreated()->json('data');

        // Customer returns 500, replaces with 1 item of 150 (net refund to customer: 350)
        // Exchange credit is 150. Card covers 150 of credit first.
        // Card refund remaining: 300 - 150 = 150.
        // Cash refund remaining: 200.
        $res = $this->as($token)->postJson("/api/v1/pos/sales/{$sale['id']}/exchange", [
            'returned_items' => [['product_variant_id' => $this->variant->id, 'qty' => 5]],
            'exchange_items' => [['product_variant_id' => $replacement->id, 'qty' => 1]],
            'cash_amount' => 0,
            'card_amount' => 0,
        ], $this->idem())->assertOk()->json('data');

        // API response contract check
        $this->assertSame(350.0, (float) $res['refund_amount']);
        $this->assertSame(200.0, (float) $res['cash_refund_amount']);
        $this->assertSame(150.0, (float) $res['card_refund_amount']);
        $this->assertSame(150.0, (float) $res['exchange_credit']);

        $returnOrder = Order::findOrFail($res['return_order_id']);
        $this->assertSame('200.00', (string) $returnOrder->cash_amount);
        $this->assertSame('150.00', (string) $returnOrder->card_amount);
        $this->assertSame('150.00', (string) $returnOrder->exchange_credit);
        $this->assertTrue((bool) $returnOrder->requires_refund);
    }

    public function test_exchange_succeeds_when_original_customer_is_soft_deleted_or_suspended(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.sales.refund']);
        $replacement = $this->makeVariant(100.00);
        $this->stockIn($this->branch->id, $replacement, 10);

        $customer = Customer::create([
            'name' => 'Mohamed Exchange',
            'phone' => '01011112233',
            'status' => CustomerStatus::Approved,
        ]);

        $sale = $this->as($token)->postJson('/api/v1/pos/sales', [
            'customer_id' => $customer->id,
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'cash_amount' => 100,
            'card_amount' => 0,
        ], $this->idem())->assertCreated()->json('data');

        // Now soft-delete customer
        $customer->delete();

        // Exchange should not crash or throw 422
        $res = $this->as($token)->postJson("/api/v1/pos/sales/{$sale['id']}/exchange", [
            'returned_items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'exchange_items' => [['product_variant_id' => $replacement->id, 'qty' => 1]],
            'cash_amount' => 0,
            'card_amount' => 0,
        ], $this->idem())->assertOk()->json('data');

        $exchangeOrder = Order::findOrFail($res['exchange_order_id']);
        $this->assertNull($exchangeOrder->customer_id);
    }

    public function test_exchange_rejects_discount_when_cashier_lacks_discount_permission(): void
    {
        // Cashier without pos.discount
        $token = $this->tokenFor($this->cashier, ['pos.sales.refund']);
        $replacement = $this->makeVariant(100.00);
        $this->stockIn($this->branch->id, $replacement, 10);

        $sale = $this->as($token)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'cash_amount' => 100,
            'card_amount' => 0,
        ], $this->idem())->assertCreated()->json('data');

        $this->as($token)->postJson("/api/v1/pos/sales/{$sale['id']}/exchange", [
            'returned_items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'exchange_items' => [[
                'product_variant_id' => $replacement->id,
                'qty' => 1,
                'discount_amount' => 10,
            ]],
            'cash_amount' => 0,
            'card_amount' => 0,
        ], $this->idem())->assertForbidden();

        $this->as($token)->postJson("/api/v1/pos/sales/{$sale['id']}/exchange", [
            'returned_items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'exchange_items' => [[
                'product_variant_id' => $replacement->id,
                'qty' => 1,
                'unit_price' => 80,
                'override_reason' => 'Manual price',
            ]],
            'cash_amount' => 0,
            'card_amount' => 0,
        ], $this->idem())->assertForbidden();
    }
}
