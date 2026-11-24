<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\Inventory\StockBucket;
use App\Enums\Inventory\StockMovementType;
use App\Enums\Orders\OrderStatus;
use App\Enums\Orders\ReturnStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\ProductVariant;
use App\Models\ReturnRequest;
use App\Models\StockLevel;
use App\Services\Inventory\InventoryService;
use App\Services\Pos\PosService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;

class PosCompletedOrdersAndReturnsTest extends PosScenarioFixture
{
    use LazilyRefreshDatabase;

    private function asToken(string $token): static
    {
        Auth::forgetGuards();
        app()->forgetScopedInstances();

        return $this->withToken($token);
    }

    public function test_pos_sales_history_lists_branch_completed_sales_with_arabic_search_and_returnable_qty(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.sales.view', 'pos.sales.refund']);
        $customer = Customer::factory()->create([
            'name' => 'أحمد محمود',
            'phone' => '+201012345678',
        ]);

        // Create two POS sales on branch A
        $sale1Id = (int) $this->asToken($token)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 3]],
            'customer_id' => $customer->id,
            'payment_method' => 'cash',
            'cash_amount' => 300,
            'card_amount' => 0,
        ], $this->idem())->assertCreated()->json('data.id');

        $v2 = $this->makeVariant(220.00);
        $this->stockIn($this->branch->id, $v2, 10);
        $sale2Id = (int) $this->asToken($token)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $v2->id, 'qty' => 2]],
            'payment_method' => 'card',
            'cash_amount' => 0,
            'card_amount' => 440,
        ], $this->idem())->assertCreated()->json('data.id');

        // Partial return of 1 unit from sale1 (leaves 2 returnable)
        $this->asToken($token)->postJson("/api/v1/pos/sales/{$sale1Id}/return", [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
        ], $this->idem())->assertOk();

        // Create a sale on Branch B (must NEVER appear in Branch A history)
        $foreignToken = $this->openShift($this->cashierB, $this->drawerB, 0.0);
        $this->stockIn($this->branchB->id, $this->variant, 5, $this->cashierB->id);
        $this->asToken($foreignToken)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0, 1), $this->idem())->assertCreated();

        // Unfiltered list on Branch A: returns sale2 & sale1 (2 delivered sales, excludes the return order)
        $res = $this->asToken($token)->getJson('/api/v1/pos/sales/history');
        $res->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $sale2Id)
            ->assertJsonPath('data.0.returnable_qty', 2)
            ->assertJsonPath('data.1.id', $sale1Id)
            ->assertJsonPath('data.1.returnable_qty', 2);

        // Search by customer phone using Eastern Arabic numerals without knowing invoice number
        $this->asToken($token)->getJson('/api/v1/pos/sales/history?q='.urlencode('٠١٠١٢٣٤٥٦٧٨'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $sale1Id);

        // Search by SKU finds sale2
        $this->asToken($token)->getJson('/api/v1/pos/sales/history?q='.urlencode($v2->sku))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $sale2Id);
    }

    public function test_completed_branch_orders_lists_store_wholesale_pos_and_receives_branch_return_without_touching_drawer(): void
    {
        $token = $this->tokenFor($this->cashier, ['orders.view', 'pos.sales.refund']);
        $customer = Customer::factory()->create([
            'name' => 'سارة علي',
            'phone' => '+201099887766',
        ]);

        // Delivered store order wholly allocated to Branch A
        $storeOrder = Order::factory()->create([
            'channel' => 'store',
            'customer_id' => $customer->id,
            'allocated_branch_id' => $this->branch->id,
            'status' => OrderStatus::Delivered,
            'payment_method' => 'cod',
            'payment_status' => 'paid',
            'subtotal' => 200,
            'grand_total' => 200,
        ]);
        $storeItem = OrderItem::factory()->for($storeOrder)->forVariant($this->variant)->create([
            'allocated_branch_id' => $this->branch->id,
            'qty' => 2,
            'unit_price' => 100,
            'line_total' => 200,
        ]);
        OrderStatusHistory::create([
            'order_id' => $storeOrder->id,
            'from_status' => OrderStatus::Shipped,
            'to_status' => OrderStatus::Delivered,
            'notes' => 'تم التسليم',
        ]);
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $this->variant->id,
            'branch_id' => $this->branch->id,
            'type' => StockMovementType::Sale,
            'affected_bucket' => StockBucket::OnHand,
            'quantity' => -2,
            'reference_type' => Order::class,
            'reference_id' => $storeOrder->id,
            'created_by' => $this->cashier->id,
        ]);

        // Foreign branch order (must never show up or be accessible)
        $foreignOrder = Order::factory()->create([
            'channel' => 'store',
            'allocated_branch_id' => $this->branchB->id,
            'status' => OrderStatus::Delivered,
        ]);
        OrderItem::factory()->for($foreignOrder)->create(['allocated_branch_id' => $this->branchB->id]);

        $list = $this->asToken($token)->getJson('/api/v1/pos/incoming-orders/completed?q='.urlencode('سارة'));
        $list->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $storeOrder->id)
            ->assertJsonPath('data.0.order_number', $storeOrder->order_number)
            ->assertJsonPath('data.0.channel', 'store');

        // Return lookup & history also find delivered wholesale orders wholly in Branch A
        $wholesaleCustomer = Customer::factory()->wholesale()->create([
            'name' => 'شركة الهدى للتجارة',
            'status' => \App\Enums\Customer\CustomerStatus::Approved,
        ]);
        $wholesaleOrder = Order::factory()->create([
            'channel' => 'wholesale',
            'order_number' => 'WHL-BR1-00999',
            'customer_id' => $wholesaleCustomer->id,
            'allocated_branch_id' => $this->branch->id,
            'status' => OrderStatus::Delivered,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'subtotal' => 500,
            'grand_total' => 500,
        ]);
        OrderItem::factory()->for($wholesaleOrder)->forVariant($this->variant)->create([
            'allocated_branch_id' => $this->branch->id,
            'product_name_snapshot' => 'شنطة جملة جلد',
            'sku_snapshot' => $this->variant->sku,
            'qty' => 5,
            'unit_price' => 100,
            'line_total' => 500,
        ]);

        $this->asToken($token)->getJson('/api/v1/pos/sales/history?channel=wholesale')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_number', 'WHL-BR1-00999')
            ->assertJsonPath('data.0.channel', 'wholesale')
            ->assertJsonPath('data.0.returnable_qty', 5);

        $this->asToken($token)->getJson('/api/v1/pos/sales/lookup?number=WHL-BR1-00999')
            ->assertOk()
            ->assertJsonPath('data.id', $wholesaleOrder->id)
            ->assertJsonPath('data.channel', 'wholesale')
            ->assertJsonPath('data.items.0.product_name', 'شنطة جملة جلد')
            ->assertJsonPath('data.items.0.returnable_qty', 5);

        // Detail endpoint on completed branch order returns can_return = true and returnable_qty = 2
        $this->asToken($token)->getJson("/api/v1/pos/incoming-orders/{$storeOrder->id}")
            ->assertOk()
            ->assertJsonPath('data.can_return', true)
            ->assertJsonPath('data.items.0.returnable_qty', 2);

        // Foreign branch order -> 404
        $this->asToken($token)->postJson("/api/v1/pos/incoming-orders/{$foreignOrder->id}/returns", [
            'items' => [['order_item_id' => $foreignOrder->items()->firstOrFail()->id, 'qty' => 1]],
        ], $this->idem())->assertNotFound();

        $balanceBefore = $this->drawer->fresh()->current_balance;
        $onHandBefore = (float) StockLevel::query()->where('branch_id', $this->branch->id)->where('product_variant_id', $this->variant->id)->sum('on_hand');

        // Receive 1 intact + check damaged handling works too (let's return 1 intact now)
        $retKey = $this->idem('branch-ret-1');
        $ret = $this->asToken($token)->postJson("/api/v1/pos/incoming-orders/{$storeOrder->id}/returns", [
            'reason' => 'المقاس غير مناسب',
            'reason_type' => 'change_of_mind',
            'items' => [
                ['order_item_id' => $storeItem->id, 'qty' => 1, 'return_to' => 'on_hand'],
            ],
        ], $retKey);
        $ret->assertCreated()
            ->assertJsonPath('data.status', ReturnStatus::Received->value)
            ->assertJsonPath('data.total_refund_amount', 100);

        // Idempotent replay
        $this->asToken($token)->postJson("/api/v1/pos/incoming-orders/{$storeOrder->id}/returns", [
            'reason' => 'المقاس غير مناسب',
            'reason_type' => 'change_of_mind',
            'items' => [
                ['order_item_id' => $storeItem->id, 'qty' => 1, 'return_to' => 'on_hand'],
            ],
        ], $retKey)->assertOk();

        // Stock increased by 1, drawer balance UNCHANGED (store order refund stays for Backoffice Finance)
        $onHandAfter = (float) StockLevel::query()->where('branch_id', $this->branch->id)->where('product_variant_id', $this->variant->id)->sum('on_hand');
        $this->assertSame($onHandBefore + 1.0, $onHandAfter);
        $this->assertSame($balanceBefore, $this->drawer->fresh()->current_balance);
        $this->assertSame(1, ReturnRequest::query()->where('order_id', $storeOrder->id)->count());

        // Over-return (requesting 2 when only 1 remains) -> 422
        $this->asToken($token)->postJson("/api/v1/pos/incoming-orders/{$storeOrder->id}/returns", [
            'items' => [
                ['order_item_id' => $storeItem->id, 'qty' => 2, 'return_to' => 'on_hand'],
            ],
        ], $this->idem())->assertStatus(422);
    }
}
