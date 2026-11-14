<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\Orders\OrderStatus;
use App\Enums\Orders\ReturnStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReturnRequest;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\Orders\ReturnService;
use Database\Seeders\ReturnPermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Task2Slice2ConcurrencyAndExpiryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ReturnPermissionSeeder::class);
        $this->staff = User::factory()->create();
        $this->staff->assignRole('Super Admin');
        $this->branch = Branch::factory()->create();
    }

    public function test_critical01_payment_status_check_allows_partially_refunded_and_refund_pending(): void
    {
        $order = Order::factory()->create(['payment_status' => 'paid']);

        $order->update(['payment_status' => 'partially_refunded']);
        $this->assertSame('partially_refunded', $order->fresh()->payment_status);

        $order->update(['payment_status' => 'refund_pending']);
        $this->assertSame('refund_pending', $order->fresh()->payment_status);
    }

    public function test_critical03_damaged_return_without_receiving_location_does_not_increase_available_stock(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['status' => 'published']);
        $variant = $product->variants()->first() ?? $product->variants()->create([
            'name_ar' => 'قياسي',
            'sku' => 'SKU-'.uniqid(),
            'retail_price' => 100,
            'is_active' => true,
        ]);

        // No locations on this branch => receivingLocation() returns null.
        StockLevel::query()->forceCreate([
            'branch_id' => $this->branch->id,
            'location_id' => null,
            'product_variant_id' => $variant->id,
            'on_hand' => 0,
            'reserved' => 0,
            'damaged' => 0,
        ]);

        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::Delivered,
            'payment_method' => 'cod',
            'payment_status' => 'paid',
            'allocated_branch_id' => $this->branch->id,
            'subtotal' => 100,
            'grand_total' => 100,
        ]);
        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'from_status' => OrderStatus::Shipped,
            'to_status' => OrderStatus::Delivered,
        ]);
        $orderItem = OrderItem::query()->create([
            'order_id' => $order->id,
            'product_variant_id' => $variant->id,
            'product_name_snapshot' => 'Bag',
            'sku_snapshot' => $variant->sku,
            'qty' => 1,
            'unit_price' => 100,
            'line_total' => 100,
            'allocated_branch_id' => $this->branch->id,
        ]);

        $returns = app(ReturnService::class);
        $ret = $returns->createReturnRequest($order, [
            'reason' => 'Damaged',
            'items' => [['order_item_id' => $orderItem->id, 'requested_qty' => 1]],
        ], $customer);
        $ret = $returns->approve($ret, $this->staff, ['branch_id' => $this->branch->id]);
        $returns->receive($ret, $this->staff, [
            'items' => [[
                'id' => $ret->items->first()->id,
                'received_good_qty' => 0,
                'received_damaged_qty' => 1,
            ]],
        ]);

        $level = StockLevel::query()
            ->where('branch_id', $this->branch->id)
            ->whereNull('location_id')
            ->where('product_variant_id', $variant->id)
            ->firstOrFail();

        $available = (float) $level->on_hand - (float) $level->reserved - (float) $level->damaged;
        $this->assertSame(1.0, (float) $level->on_hand);
        $this->assertSame(1.0, (float) $level->damaged);
        $this->assertSame(0.0, $available);
    }

    public function test_high01_unique_index_blocks_duplicate_refund_reference_at_db_level(): void
    {
        $customer = Customer::factory()->create();
        $orderA = Order::factory()->create(['customer_id' => $customer->id]);
        $orderB = Order::factory()->create(['customer_id' => $customer->id]);

        ReturnRequest::query()->create([
            'return_number' => 'RET-1001',
            'order_id' => $orderA->id,
            'customer_id' => $customer->id,
            'status' => ReturnStatus::Refunded,
            'refund_reference' => 'BANK-TX-99',
            'total_refund_amount' => 100,
            'reason' => 'R1',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);
        ReturnRequest::query()->create([
            'return_number' => 'RET-1002',
            'order_id' => $orderB->id,
            'customer_id' => $customer->id,
            'status' => ReturnStatus::Refunded,
            'refund_reference' => ' bank-tx-99 ',
            'total_refund_amount' => 100,
            'reason' => 'R2',
        ]);
    }

    public function test_medium07_expire_pending_orders_respects_bank_transfer_ttl_and_pending_payment_notices(): void
    {
        $customer = Customer::factory()->create();

        $cardExpired = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::Pending,
            'payment_method' => 'card',
            'payment_status' => 'unpaid',
        ]);
        DB::table('orders')->where('id', $cardExpired->id)->update(['created_at' => now()->subHours(2)]);

        $bankRecent = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::Pending,
            'payment_method' => 'bank_transfer',
            'payment_status' => 'unpaid',
        ]);
        DB::table('orders')->where('id', $bankRecent->id)->update(['created_at' => now()->subHours(2)]);

        $bankOldNoNotice = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::Pending,
            'payment_method' => 'bank_transfer',
            'payment_status' => 'unpaid',
        ]);
        DB::table('orders')->where('id', $bankOldNoNotice->id)->update(['created_at' => now()->subHours(50)]);

        $bankOldWithNotice = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::Pending,
            'payment_method' => 'bank_transfer',
            'payment_status' => 'unpaid',
        ]);
        DB::table('orders')->where('id', $bankOldWithNotice->id)->update(['created_at' => now()->subHours(50)]);
        WholesalePaymentNotice::query()->forceCreate([
            'notice_number' => 'WPN-1',
            'order_id' => $bankOldWithNotice->id,
            'customer_id' => $customer->id,
            'amount' => 100,
            'payment_method' => 'bank_transfer',
            'bank_name' => 'CIB',
            'reference_number' => 'REF-NOTICE-1',
            'transfer_date' => now()->toDateString(),
            'receipt_file_path' => 'wholesale_payment_notices/1/receipt.jpg',
            'status' => \App\Enums\Wholesale\WholesalePaymentNoticeStatus::PendingReview->value,
        ]);

        $this->artisan('orders:expire-pending')->assertExitCode(0);

        $this->assertSame(OrderStatus::Cancelled, $cardExpired->fresh()->status);
        $this->assertSame(OrderStatus::Pending, $bankRecent->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $bankOldNoNotice->fresh()->status);
        $this->assertSame(OrderStatus::Pending, $bankOldWithNotice->fresh()->status);
    }
}
