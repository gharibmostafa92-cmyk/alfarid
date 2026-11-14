<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\Orders\OrderStatus;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\City;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\ReturnService;
use Database\Seeders\ReturnPermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class Task2RoadmapFixesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    private Branch $branch;

    private Location $receiving;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ReturnPermissionSeeder::class);
        $this->staff = User::factory()->create();
        $this->staff->assignRole('Super Admin');
        $this->branch = Branch::factory()->create();
        $this->receiving = Location::factory()->create(['branch_id' => $this->branch->id, 'type' => 'receiving']);
    }

    private function purchasableVariant(float $price = 100): ProductVariant
    {
        $product = Product::factory()->create(['status' => 'published']);
        $variant = $product->variants()->first() ?? $product->variants()->create([
            'name_ar' => 'قياسي',
            'sku' => 'SKU-'.uniqid(),
            'retail_price' => $price,
            'is_active' => true,
        ]);
        $variant->update([
            'retail_price' => $price,
        ]);

        return $variant->fresh();
    }

    private function giveStock(ProductVariant $variant, float $qty): StockLevel
    {
        $location = Location::factory()->create(['branch_id' => $this->branch->id]);

        return StockLevel::query()->forceCreate([
            'branch_id' => $this->branch->id,
            'location_id' => $location->id,
            'product_variant_id' => $variant->id,
            'on_hand' => $qty,
            'reserved' => 0,
        ]);
    }

    public function test_critical02_caps_distributed_coupon_discount_to_effective_remaining_discount(): void
    {
        $group = \App\Models\CustomerGroup::query()->forceCreate([
            'name' => 'VIP',
            'code' => 'VIP',
            'discount_percentage' => 20,
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['customer_group_id' => $group->id]);
        $city = City::factory()->create(['shipping_cost' => 0]);
        // Base retail 100, 20% group discount => unit_price 80, pricing_discount = 20, items_total = 80
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 5);

        $coupon = Coupon::query()->create([
            'code' => 'FLAT100',
            'type' => 'fixed',
            'value' => 100,
            'is_active' => true,
        ]);

        $cart = Cart::query()->create([
            'customer_id' => $customer->id,
            'coupon_id' => $coupon->id,
        ]);
        $cart->items()->create([
            'product_variant_id' => $variant->id,
            'qty' => 1,
        ]);

        $order = app(OrderService::class)->createFromCart($cart, [
            'city_id' => $city->id,
            'shipping_address_line' => 'Cairo',
            'payment_method' => 'cod',
        ], $customer);

        $item = $order->items->firstOrFail();
        // Order subtotal = 100, discount_amount = 100 (20 pricing + 80 coupon), grand_total = 0.
        // Item coupon_discount_amount must be capped at 80.00 (not 100.00), and item discount_amount = 100.00.
        $this->assertSame('80.00', number_format((float) $item->coupon_discount_amount, 2, '.', ''));
        $this->assertSame('100.00', number_format((float) $item->discount_amount, 2, '.', ''));
    }

    public function test_critical02_legacy_null_coupon_discount_does_not_double_subtract_pricing_discount(): void
    {
        $customer = Customer::factory()->create();
        $variant = $this->purchasableVariant(100);

        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::Delivered,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'allocated_branch_id' => $this->branch->id,
            'subtotal' => 200.00,
            'discount_amount' => 40.00,
            'tax_amount' => 0.00,
            'shipping_cost' => 0.00,
            'cod_fee' => 0.00,
            'grand_total' => 160.00,
        ]);
        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'from_status' => OrderStatus::Shipped,
            'to_status' => OrderStatus::Delivered,
        ]);

        // Legacy item: unit_price = 80 (already has 20 pricing discount), qty = 2, line_total = 160,
        // discount_amount = 40 (the pricing discount), coupon_discount_amount = null.
        $orderItem = OrderItem::query()->create([
            'order_id' => $order->id,
            'product_variant_id' => $variant->id,
            'product_name_snapshot' => 'Bag',
            'sku_snapshot' => $variant->sku,
            'qty' => 2,
            'unit_price' => 80.00,
            'discount_amount' => 40.00,
            'coupon_discount_amount' => null,
            'tax_amount' => 0.00,
            'line_total' => 160.00,
            'allocated_branch_id' => $this->branch->id,
        ]);

        $returns = app(ReturnService::class);
        $ret = $returns->createReturnRequest($order, [
            'reason' => 'Defect',
            'items' => [['order_item_id' => $orderItem->id, 'requested_qty' => 2]],
        ], $customer);
        $ret = $returns->approve($ret, $this->staff, ['branch_id' => $this->branch->id]);
        $ret = $returns->receive($ret, $this->staff, [
            'items' => [[
                'id' => $ret->items->first()->id,
                'received_good_qty' => 2,
                'received_damaged_qty' => 0,
            ]],
        ]);

        $this->assertSame('160.00', number_format((float) $ret->total_refund_amount, 2, '.', ''));
    }

    public function test_critical02_proportional_partial_refund_avoids_four_decimal_truncation_drift(): void
    {
        $customer = Customer::factory()->create();
        $variant = $this->purchasableVariant(1.00);

        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::Delivered,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'allocated_branch_id' => $this->branch->id,
            'subtotal' => 1000.00,
            'discount_amount' => 0.00,
            'tax_amount' => 0.00,
            'shipping_cost' => 0.00,
            'cod_fee' => 0.00,
            'grand_total' => 1000.00,
        ]);
        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'from_status' => OrderStatus::Shipped,
            'to_status' => OrderStatus::Delivered,
        ]);

        // 3000 units for 1000.00 -> 1000/3000 = 0.3333... (4-decimal truncation loses 7 cents on 2000 units)
        $orderItem = OrderItem::query()->create([
            'order_id' => $order->id,
            'product_variant_id' => $variant->id,
            'product_name_snapshot' => 'Bag',
            'sku_snapshot' => $variant->sku,
            'qty' => 3000,
            'unit_price' => 0.33,
            'discount_amount' => 0.00,
            'coupon_discount_amount' => 0.00,
            'tax_amount' => 0.00,
            'line_total' => 1000.00,
            'allocated_branch_id' => $this->branch->id,
        ]);

        $returns = app(ReturnService::class);
        $ret = $returns->createReturnRequest($order, [
            'reason' => 'Partial',
            'items' => [['order_item_id' => $orderItem->id, 'requested_qty' => 2000]],
        ], $customer);
        $ret = $returns->approve($ret, $this->staff, ['branch_id' => $this->branch->id]);
        $ret = $returns->receive($ret, $this->staff, [
            'items' => [[
                'id' => $ret->items->first()->id,
                'received_good_qty' => 2000,
                'received_damaged_qty' => 0,
            ]],
        ]);

        $this->assertSame('666.67', number_format((float) $ret->total_refund_amount, 2, '.', ''));
    }

    public function test_high02_split_refunds_gateway_and_wallet_sync_to_refunded(): void
    {
        $customer = Customer::factory()->create();
        $variantA = $this->purchasableVariant(600);
        $variantB = $this->purchasableVariant(400);

        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::Delivered,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'allocated_branch_id' => $this->branch->id,
            'subtotal' => 1000.00,
            'discount_amount' => 0.00,
            'tax_amount' => 0.00,
            'shipping_cost' => 0.00,
            'cod_fee' => 0.00,
            'grand_total' => 1000.00,
        ]);
        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'from_status' => OrderStatus::Shipped,
            'to_status' => OrderStatus::Delivered,
        ]);
        $itemA = OrderItem::query()->create([
            'order_id' => $order->id,
            'product_variant_id' => $variantA->id,
            'product_name_snapshot' => 'Bag A',
            'sku_snapshot' => $variantA->sku,
            'qty' => 1,
            'unit_price' => 600.00,
            'discount_amount' => 0.00,
            'coupon_discount_amount' => 0.00,
            'tax_amount' => 0.00,
            'line_total' => 600.00,
            'allocated_branch_id' => $this->branch->id,
        ]);
        $itemB = OrderItem::query()->create([
            'order_id' => $order->id,
            'product_variant_id' => $variantB->id,
            'product_name_snapshot' => 'Bag B',
            'sku_snapshot' => $variantB->sku,
            'qty' => 1,
            'unit_price' => 400.00,
            'discount_amount' => 0.00,
            'coupon_discount_amount' => 0.00,
            'tax_amount' => 0.00,
            'line_total' => 400.00,
            'allocated_branch_id' => $this->branch->id,
        ]);

        $returns = app(ReturnService::class);

        // Return 1 (600) via gateway original_payment
        $order->forceFill(['gateway_refunded_amount' => '600.00', 'payment_status' => 'partially_refunded'])->save();
        $ret1 = $returns->createReturnRequest($order, [
            'reason' => 'Item A',
            'items' => [['order_item_id' => $itemA->id, 'requested_qty' => 1]],
        ], $customer);
        $ret1 = $returns->approve($ret1, $this->staff, ['branch_id' => $this->branch->id]);
        $ret1 = $returns->receive($ret1, $this->staff, [
            'items' => [['id' => $ret1->items->first()->id, 'received_good_qty' => 1, 'received_damaged_qty' => 0]],
        ]);
        $returns->refund($ret1, $this->staff, [
            'refund_method' => 'original_payment',
            'refund_reference' => 'GW-REF-1',
        ]);
        $this->assertSame('partially_refunded', $order->fresh()->payment_status);

        // Return 2 (400) via wallet after gateway_refunded_amount (600) is fully allocated
        $ret2 = $returns->createReturnRequest($order->fresh(), [
            'reason' => 'Item B',
            'items' => [['order_item_id' => $itemB->id, 'requested_qty' => 1]],
        ], $customer);
        $ret2 = $returns->approve($ret2, $this->staff, ['branch_id' => $this->branch->id]);
        $ret2 = $returns->receive($ret2, $this->staff, [
            'items' => [['id' => $ret2->items->first()->id, 'received_good_qty' => 1, 'received_damaged_qty' => 0]],
        ]);
        $returns->refund($ret2, $this->staff, [
            'refund_method' => 'wallet',
        ]);

        $freshOrder = $order->fresh();
        $this->assertSame('refunded', $freshOrder->payment_status);
        $this->assertFalse($freshOrder->requires_refund);
    }

    public function test_medium05_return_window_uses_end_of_day_in_business_timezone(): void
    {
        config(['app.business_timezone' => 'Africa/Cairo']);
        $customer = Customer::factory()->create();
        $variant = $this->purchasableVariant(100);

        $deliveredAt = Carbon::parse('2026-11-01 10:00:00', 'Africa/Cairo')->utc();
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::Delivered,
            'payment_method' => 'cod',
            'payment_status' => 'paid',
            'subtotal' => 100.00,
            'grand_total' => 100.00,
        ]);
        $history = OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'from_status' => OrderStatus::Shipped,
            'to_status' => OrderStatus::Delivered,
        ]);
        $history->forceFill(['created_at' => $deliveredAt])->save();

        $orderItem = OrderItem::query()->create([
            'order_id' => $order->id,
            'product_variant_id' => $variant->id,
            'product_name_snapshot' => 'Bag',
            'sku_snapshot' => $variant->sku,
            'qty' => 1,
            'unit_price' => 100.00,
            'line_total' => 100.00,
        ]);

        // Day 14 after 10:00 AM (11:00 AM) must still be within window until 23:59:59
        $this->travelTo(Carbon::parse('2026-11-15 11:00:00', 'Africa/Cairo'));
        $ret = app(ReturnService::class)->createReturnRequest($order, [
            'reason' => 'Day 14 afternoon',
            'items' => [['order_item_id' => $orderItem->id, 'requested_qty' => 1]],
        ], $customer);

        $this->assertNotNull($ret->id);
        $this->travelBack();
    }
}
