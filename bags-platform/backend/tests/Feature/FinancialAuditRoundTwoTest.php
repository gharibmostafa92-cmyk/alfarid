<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\ReturnRequest;
use App\Models\ReturnRequestItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinancialAuditRoundTwoTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
        $this->staff = User::factory()->create(['branch_id' => $this->branch->id]);
    }

    /** @return array{Order, OrderItem, ProductVariant} */
    private function shippedSale(array $attributes = [], string $saleDate = '2026-02-05', ?string $unitCost = '100.00'): array
    {
        $variant = ProductVariant::factory()->create(['cost_price' => '999.00']);
        $order = Order::factory()->create([
            'status' => 'delivered', 'allocated_branch_id' => $this->branch->id,
            'subtotal' => '1000.00', 'discount_amount' => '100.00',
            'tax_amount' => '0.00', 'shipping_cost' => '0.00', 'cod_fee' => '0.00',
            'grand_total' => '900.00', 'created_at' => '2026-01-10 12:00:00',
            ...$attributes,
        ]);
        $item = OrderItem::factory()->forVariant($variant)->create([
            'order_id' => $order->id, 'allocated_branch_id' => $this->branch->id,
            'qty' => 2, 'unit_price' => 500, 'discount_amount' => 100, 'line_total' => 900,
        ]);
        $this->movement($variant, 'sale', -2, $unitCost, Order::class, $order->id, $saleDate);

        return [$order, $item, $variant];
    }

    private function movement(ProductVariant $variant, string $type, int $quantity, ?string $unitCost, string $referenceType, int $referenceId, string $date): void
    {
        $movement = new StockMovement([
            'reference_number' => 'AUDIT-'.Str::uuid(),
            'product_variant_id' => $variant->id, 'branch_id' => $this->branch->id,
            'type' => $type, 'affected_bucket' => 'on_hand', 'quantity' => $quantity,
            'balance_before' => 10, 'balance_after' => 10 + $quantity,
            'unit_cost' => $unitCost, 'reference_type' => $referenceType,
            'reference_id' => $referenceId, 'created_by' => $this->staff->id,
        ]);
        $movement->created_at = "{$date} 12:00:00";
        $movement->save();
    }

    private function report(string $from, string $to): array
    {
        return app(ReportService::class)->profitReport($from, $to, $this->branch->id);
    }

    public function test_merchandise_profit_excludes_tax_shipping_and_cod_fees(): void
    {
        $this->shippedSale([
            'created_at' => '2026-02-05 12:00:00',
            'tax_amount' => '126.00', 'shipping_cost' => '50.00', 'cod_fee' => '25.00',
            'grand_total' => '1101.00',
        ]);

        $report = $this->report('2026-02-01', '2026-02-28');
        $this->assertSame(900.0, (float) $report['net_sales_revenue']);
        $this->assertSame(200.0, (float) $report['cogs']);
        $this->assertSame(700.0, (float) $report['gross_profit']);
    }

    public function test_revenue_and_cogs_are_recognized_in_the_sale_movement_period(): void
    {
        $this->shippedSale();

        $creationPeriod = $this->report('2026-01-01', '2026-01-31');
        $this->assertSame(0.0, (float) $creationPeriod['net_sales_revenue']);
        $this->assertSame(0.0, (float) $creationPeriod['cogs']);
        $salePeriod = $this->report('2026-02-01', '2026-02-28');
        $this->assertSame(900.0, (float) $salePeriod['net_sales_revenue']);
        $this->assertSame(200.0, (float) $salePeriod['cogs']);
        $this->assertSame(700.0, (float) $salePeriod['net_profit']);
    }

    public static function receivedReturns(): array
    {
        return [['delivered', 1, 450], ['returned', 2, 900]];
    }

    #[DataProvider('receivedReturns')]
    public function test_received_returns_reverse_the_return_period_without_erasing_the_original_sale(string $status, int $quantity, int $refund): void
    {
        [$order, $item, $variant] = $this->shippedSale(['status' => $status]);
        $return = ReturnRequest::factory()->create([
            'order_id' => $order->id, 'branch_id' => $this->branch->id,
            'status' => 'received', 'received_at' => '2026-03-05 12:00:00',
            'total_refund_amount' => $refund,
        ]);
        ReturnRequestItem::factory()->create([
            'return_request_id' => $return->id, 'order_item_id' => $item->id,
            'product_variant_id' => $variant->id, 'requested_qty' => $quantity,
            'approved_qty' => $quantity, 'received_good_qty' => $quantity,
            'unit_refund_price' => 450, 'refund_amount' => $refund,
        ]);
        $this->movement($variant, 'sale_return', $quantity, '100.00', ReturnRequest::class, $return->id, '2026-03-05');

        $salePeriod = $this->report('2026-02-01', '2026-02-28');
        $this->assertSame(900.0, (float) $salePeriod['net_sales_revenue']);
        $this->assertSame(200.0, (float) $salePeriod['cogs']);
        $returnPeriod = $this->report('2026-03-01', '2026-03-31');
        $this->assertSame((float) -$refund, (float) $returnPeriod['net_sales_revenue']);
        $this->assertSame((float) (-100 * $quantity), (float) $returnPeriod['cogs']);
        $this->assertSame((float) (-$refund + 100 * $quantity), (float) $returnPeriod['net_profit']);
    }

    public function test_pos_return_document_reverses_revenue_once_in_its_own_period(): void
    {
        [$original, , $variant] = $this->shippedSale(['channel' => 'pos', 'status' => 'returned', 'updated_at' => '2026-03-05 12:00:00']);
        Order::factory()->create([
            'return_of_order_id' => $original->id, 'allocated_branch_id' => $this->branch->id,
            'channel' => 'pos', 'status' => 'returned', 'payment_status' => 'refunded',
            'subtotal' => 900, 'discount_amount' => 0, 'tax_amount' => 0,
            'shipping_cost' => 0, 'cod_fee' => 0, 'grand_total' => 900,
            'created_at' => '2026-03-05 12:00:00',
        ]);
        // POS return movements reference the original order, unlike RMA movements.
        $this->movement($variant, 'sale_return', 2, '100.00', Order::class, $original->id, '2026-03-05');

        $salePeriod = $this->report('2026-02-01', '2026-02-28');
        $this->assertSame(900.0, (float) $salePeriod['net_sales_revenue']);
        $returnPeriod = $this->report('2026-03-01', '2026-03-31');
        $this->assertSame(-900.0, (float) $returnPeriod['net_sales_revenue']);
        $this->assertSame(-200.0, (float) $returnPeriod['cogs']);
    }

    public function test_missing_historical_cost_is_flagged_and_never_replaced_with_current_cost(): void
    {
        [, , $variant] = $this->shippedSale([], '2026-02-05', null);

        $first = $this->report('2026-02-01', '2026-02-28');
        $this->assertSame(0.0, (float) $first['cogs']);
        $this->assertArrayHasKey('missing_cost_movements', $first);
        $this->assertSame(1, $first['missing_cost_movements']);

        $variant->update(['cost_price' => '1234.00']);
        $second = $this->report('2026-02-01', '2026-02-28');
        $this->assertSame($first['cogs'], $second['cogs']);
        $this->assertSame(1, $second['missing_cost_movements']);
    }
}
