<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\Finance\PosShiftStatus;
use App\Enums\Finance\TreasuryType;
use App\Enums\Inventory\StockBucket;
use App\Enums\Inventory\StockMovementType;
use App\Enums\Orders\OrderStatus;
use App\Enums\Wholesale\WholesalePaymentNoticeStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosShift;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\ShippingCompany;
use App\Models\StockLevel;
use App\Models\Treasury;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class IncomingOrdersTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_cashier_sees_both_channels_and_can_only_advance_wholly_local_orders(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $other = Branch::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        foreach (['pos.access', 'orders.view', 'orders.update'] as $name) {
            $actor->givePermissionTo(Permission::findOrCreate($name, 'staff'));
        }
        $drawer = Treasury::create([
            'name' => 'Drawer', 'code' => 'ORDER-TEST-DRAWER', 'branch_id' => $branch->id,
            'type' => TreasuryType::PosDrawer, 'current_balance' => 0, 'is_active' => true,
        ]);
        PosShift::create([
            'shift_number' => 'ORDER-TEST-SHIFT', 'user_id' => $actor->id, 'branch_id' => $branch->id,
            'treasury_id' => $drawer->id, 'status' => PosShiftStatus::Open, 'opened_at' => now(), 'opening_cash' => 0,
        ]);
        $this->withToken($actor->createToken('pos', ['pos'])->plainTextToken);

        $retail = Order::factory()->create([
            'allocated_branch_id' => $branch->id, 'status' => OrderStatus::Confirmed,
            'guest_name' => 'عميل', 'guest_phone' => '01012345678',
            'shipping_address_line' => 'عنوان التسليم', 'shipping_notes' => 'اتصل عند الوصول',
            'payment_method' => 'cod', 'payment_status' => 'unpaid',
        ]);
        OrderItem::factory()->for($retail)->create(['allocated_branch_id' => $branch->id]);
        $split = Order::factory()->create(['channel' => 'wholesale']);
        $localItem = OrderItem::factory()->for($split)->create(['allocated_branch_id' => $branch->id]);
        OrderItem::factory()->for($split)->create(['allocated_branch_id' => $other->id]);
        $remote = Order::factory()->create(['allocated_branch_id' => $other->id]);
        Order::factory()->create(['channel' => 'pos', 'allocated_branch_id' => $branch->id]);
        Order::factory()->create();

        $url = '/api/v1/pos/incoming-orders';
        $this->getJson($url)->assertOk()->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.id', $split->id)->assertJsonPath('data.1.id', $retail->id);
        $this->getJson("$url/{$remote->id}")->assertNotFound();
        $this->getJson("$url/{$split->id}")->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $localItem->id)->assertJsonPath('data.next_status', null)
            ->assertJsonPath('data.delivery', null)->assertJsonPath('data.payment_method', null);
        $this->getJson("$url/{$retail->id}")->assertOk()
            ->assertJsonPath('data.delivery.name', 'عميل')
            ->assertJsonPath('data.delivery.phone', '01012345678')
            ->assertJsonPath('data.delivery.address', 'عنوان التسليم')
            ->assertJsonPath('data.delivery.notes', 'اتصل عند الوصول')
            ->assertJsonPath('data.payment_method', 'cod');
        $this->postJson("$url/{$split->id}/status", ['expected_status' => 'pending', 'status' => 'confirmed'], $this->idemHeaders())
            ->assertNotFound();

        $command = ['expected_status' => 'confirmed', 'status' => 'processing'];
        $key = $this->idemHeaders();
        $this->postJson("$url/{$retail->id}/status", $command, $key)->assertOk();
        $this->postJson("$url/{$retail->id}/status", $command, $key)->assertOk();
        $this->postJson("$url/{$retail->id}/status", $command, $this->idemHeaders())->assertConflict();
        $this->assertSame(1, $retail->statusHistories()->count());
        $this->getJson("$url/events?after=0")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_id', $retail->id)->assertJsonPath('data.0.kind', 'updated')
            ->assertJsonPath('position', 1);
        $this->getJson("$url/events?after=1")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("$url/events?after=50")->assertOk()->assertJsonPath('reset', true);

        DB::table('pos_order_events')->insert(array_fill(0, 201, [
            'branch_id' => $branch->id, 'order_id' => $retail->id, 'kind' => 'arrival', 'created_at' => now(),
        ]));
        $this->getJson($url)->assertOk()->assertJsonPath('position', 201)->assertJsonPath('catching_up', true);
        $this->getJson("$url/events?after=201")->assertOk()->assertJsonPath('position', 202)->assertJsonPath('more', false);
    }

    public function test_pos_orders_cancel_permission_enforces_rbac_branch_scope_and_releases_stock(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $other = Branch::factory()->create(['is_active' => true]);
        $drawer = Treasury::create([
            'name' => 'Cancel Drawer', 'code' => 'DRW-CANCEL-1', 'branch_id' => $branch->id,
            'type' => TreasuryType::PosDrawer, 'current_balance' => 0, 'is_active' => true,
        ]);

        $restricted = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        foreach (['pos.access', 'orders.view', 'orders.update'] as $name) {
            $restricted->givePermissionTo(Permission::findOrCreate($name, 'staff'));
        }
        PosShift::create([
            'shift_number' => 'SHIFT-NO-CANCEL', 'user_id' => $restricted->id, 'branch_id' => $branch->id,
            'treasury_id' => $drawer->id, 'status' => PosShiftStatus::Open, 'opened_at' => now(), 'opening_cash' => 0,
        ]);

        $variant = ProductVariant::factory()->create(['barcode' => '6220001112223']);
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id,
            'branch_id' => $branch->id,
            'type' => StockMovementType::AdjustmentIn,
            'affected_bucket' => StockBucket::OnHand,
            'quantity' => 5,
            'created_by' => $restricted->id,
        ]);

        $order = Order::factory()->create([
            'allocated_branch_id' => $branch->id,
            'status' => OrderStatus::Confirmed,
        ]);
        OrderItem::factory()->for($order)->forVariant($variant)->create([
            'allocated_branch_id' => $branch->id,
            'qty' => 2,
        ]);
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id,
            'branch_id' => $branch->id,
            'type' => StockMovementType::Reserve,
            'affected_bucket' => StockBucket::Reserved,
            'quantity' => 2,
            'reference_type' => Order::class,
            'reference_id' => $order->id,
            'created_by' => $restricted->id,
        ]);

        $url = '/api/v1/pos/incoming-orders';
        $restrictedToken = $restricted->createToken('pos', ['pos'])->plainTextToken;

        // Without pos.orders.cancel: show reports can_cancel = false, cancel returns 403.
        $this->withToken($restrictedToken)->getJson("$url/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.can_cancel', false);
        $this->withToken($restrictedToken)->postJson("$url/{$order->id}/cancel", [
            'expected_status' => 'confirmed',
            'reason' => 'طلب العميل إلغاء الطلب',
        ], $this->idemHeaders())->assertForbidden();

        // Grant pos.orders.cancel to supervisor on same branch
        $supervisor = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        foreach (['pos.access', 'orders.view', 'orders.update', 'pos.orders.cancel'] as $name) {
            $supervisor->givePermissionTo(Permission::findOrCreate($name, 'staff'));
        }
        $drawer2 = Treasury::create([
            'name' => 'Cancel Drawer 2', 'code' => 'DRW-CANCEL-2', 'branch_id' => $branch->id,
            'type' => TreasuryType::PosDrawer, 'current_balance' => 0, 'is_active' => true,
        ]);
        PosShift::create([
            'shift_number' => 'SHIFT-CANCEL', 'user_id' => $supervisor->id, 'branch_id' => $branch->id,
            'treasury_id' => $drawer2->id, 'status' => PosShiftStatus::Open, 'opened_at' => now(), 'opening_cash' => 0,
        ]);

        Auth::forgetGuards();
        app()->forgetScopedInstances();
        $supToken = $supervisor->createToken('pos', ['pos'])->plainTextToken;

        $this->withToken($supToken)->getJson("$url/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.can_cancel', true);

        // Split multi-branch order -> 404
        $split = Order::factory()->create(['channel' => 'wholesale', 'status' => OrderStatus::Confirmed]);
        OrderItem::factory()->for($split)->create(['allocated_branch_id' => $branch->id]);
        OrderItem::factory()->for($split)->create(['allocated_branch_id' => $other->id]);
        $this->withToken($supToken)->postJson("$url/{$split->id}/cancel", [
            'expected_status' => 'confirmed',
            'reason' => 'اختبار فرع مشترك',
        ], $this->idemHeaders())->assertNotFound();

        // Shipped order -> 422
        $shipped = Order::factory()->create(['allocated_branch_id' => $branch->id, 'status' => OrderStatus::Shipped]);
        OrderItem::factory()->for($shipped)->create(['allocated_branch_id' => $branch->id]);
        $this->withToken($supToken)->postJson("$url/{$shipped->id}/cancel", [
            'expected_status' => 'confirmed',
            'reason' => 'اختبار مشحون',
        ], $this->idemHeaders())->assertUnprocessable();

        // Legitimate cancel + idempotent replay
        $cancelKey = $this->idemHeaders();
        $this->withToken($supToken)->postJson("$url/{$order->id}/cancel", [
            'expected_status' => 'confirmed',
            'reason' => 'طلب العميل إلغاء الطلب',
        ], $cancelKey)->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->withToken($supToken)->postJson("$url/{$order->id}/cancel", [
            'expected_status' => 'confirmed',
            'reason' => 'طلب العميل إلغاء الطلب',
        ], $cancelKey)->assertOk()->assertJsonPath('data.status', 'cancelled');

        $level = StockLevel::query()->where('branch_id', $branch->id)->where('product_variant_id', $variant->id)->firstOrFail();
        $this->assertSame(0.0, (float) $level->reserved);
        $this->assertSame(5.0, (float) $level->available);
        $this->assertSame(1, $order->statusHistories()->count());
        $this->assertDatabaseHas('pos_order_events', ['branch_id' => $branch->id, 'order_id' => $order->id, 'kind' => 'removed']);

        // Credit order with approved wholesale notice -> 422
        $customer = Customer::factory()->wholesale()->create(['status' => \App\Enums\Customer\CustomerStatus::Approved]);
        $creditOrder = Order::factory()->create([
            'channel' => 'wholesale',
            'customer_id' => $customer->id,
            'allocated_branch_id' => $branch->id,
            'status' => OrderStatus::Confirmed,
            'payment_method' => 'credit',
            'grand_total' => 500,
        ]);
        OrderItem::factory()->for($creditOrder)->create(['allocated_branch_id' => $branch->id]);
        WholesalePaymentNotice::create([
            'notice_number' => 'WPN-CANCEL-GUARD',
            'customer_id' => $customer->id,
            'order_id' => $creditOrder->id,
            'amount' => 200,
            'payment_method' => 'bank_transfer',
            'bank_name' => 'CIB',
            'reference_number' => 'REF-CANCEL-GUARD',
            'transfer_date' => now()->toDateString(),
            'receipt_file_path' => 'receipts/wpn-cancel-guard.jpg',
            'status' => WholesalePaymentNoticeStatus::Approved,
            'reviewed_by' => $supervisor->id,
            'approved_at' => now(),
        ]);
        $this->withToken($supToken)->postJson("$url/{$creditOrder->id}/cancel", [
            'expected_status' => 'confirmed',
            'reason' => 'محاولة إلغاء',
        ], $this->idemHeaders())->assertStatus(422);

        // Order with existing shipment -> 422
        $company = ShippingCompany::create(['name' => 'Fast', 'code' => 'FAST-1', 'is_active' => true]);
        $withShipment = Order::factory()->create([
            'allocated_branch_id' => $branch->id,
            'status' => OrderStatus::Processing,
        ]);
        OrderItem::factory()->for($withShipment)->create(['allocated_branch_id' => $branch->id]);
        Shipment::create([
            'order_id' => $withShipment->id,
            'shipping_company_id' => $company->id,
            'awb_number' => 'AWB-GUARD-1',
            'status' => 'created',
        ]);
        $this->withToken($supToken)->postJson("$url/{$withShipment->id}/cancel", [
            'expected_status' => 'processing',
            'reason' => 'محاولة إلغاء بعد البوليصة',
        ], $this->idemHeaders())->assertStatus(422);
    }

    public function test_smart_cart_prepare_validates_items_and_transitions_order_to_processing(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        foreach (['pos.access', 'orders.view', 'orders.update', 'pos.orders.cancel'] as $name) {
            $actor->givePermissionTo(Permission::findOrCreate($name, 'staff'));
        }
        $drawer = Treasury::create([
            'name' => 'Prep Drawer', 'code' => 'DRW-PREP-1', 'branch_id' => $branch->id,
            'type' => TreasuryType::PosDrawer, 'current_balance' => 0, 'is_active' => true,
        ]);
        PosShift::create([
            'shift_number' => 'SHIFT-PREP', 'user_id' => $actor->id, 'branch_id' => $branch->id,
            'treasury_id' => $drawer->id, 'status' => PosShiftStatus::Open, 'opened_at' => now(), 'opening_cash' => 0,
        ]);
        $this->withToken($actor->createToken('pos', ['pos'])->plainTextToken);

        $v1 = ProductVariant::factory()->create(['barcode' => '6229990001112']);
        $v2 = ProductVariant::factory()->create(['barcode' => '6229990001113']);
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $v1->id, 'branch_id' => $branch->id,
            'type' => StockMovementType::AdjustmentIn, 'affected_bucket' => StockBucket::OnHand,
            'quantity' => 2, 'created_by' => $actor->id,
        ]);
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $v2->id, 'branch_id' => $branch->id,
            'type' => StockMovementType::AdjustmentIn, 'affected_bucket' => StockBucket::OnHand,
            'quantity' => 1, 'created_by' => $actor->id,
        ]);

        $order = Order::factory()->create([
            'allocated_branch_id' => $branch->id,
            'status' => OrderStatus::Confirmed,
        ]);
        OrderItem::factory()->for($order)->forVariant($v1)->create(['allocated_branch_id' => $branch->id, 'qty' => 2]);
        OrderItem::factory()->for($order)->forVariant($v2)->create(['allocated_branch_id' => $branch->id, 'qty' => 1]);

        $url = '/api/v1/pos/incoming-orders';
        $this->getJson("$url/{$order->id}")->assertOk()
            ->assertJsonPath('data.can_prepare', true)
            ->assertJsonPath('data.items.0.product_variant_id', $v1->id)
            ->assertJsonPath('data.items.0.barcode', '6229990001112');

        // Mismatched qty -> 422
        $this->postJson("$url/{$order->id}/prepare", [
            'expected_status' => 'confirmed',
            'items' => [
                ['product_variant_id' => $v1->id, 'qty' => 1],
                ['product_variant_id' => $v2->id, 'qty' => 1],
            ],
        ], $this->idemHeaders())->assertStatus(422);

        // Exact match -> processing + prepared_at stamped
        $prepKey = $this->idemHeaders();
        $this->postJson("$url/{$order->id}/prepare", [
            'expected_status' => 'confirmed',
            'items' => [
                ['product_variant_id' => $v1->id, 'qty' => 2],
                ['product_variant_id' => $v2->id, 'qty' => 1],
            ],
        ], $prepKey)->assertOk()->assertJsonPath('data.status', 'processing');

        // Idempotent replay
        $this->postJson("$url/{$order->id}/prepare", [
            'expected_status' => 'confirmed',
            'items' => [
                ['product_variant_id' => $v1->id, 'qty' => 2],
                ['product_variant_id' => $v2->id, 'qty' => 1],
            ],
        ], $prepKey)->assertOk();

        $order->refresh();
        $this->assertNotNull($order->prepared_at);
        $this->assertSame($actor->id, $order->prepared_by);

        // Cancelling a prepared order clears prepared_at and prepared_by
        $this->postJson("$url/{$order->id}/cancel", [
            'expected_status' => 'processing',
            'reason' => 'إلغاء بعد التجهيز',
        ], $this->idemHeaders())->assertOk()->assertJsonPath('data.status', 'cancelled');

        $order->refresh();
        $this->assertNull($order->prepared_at);
        $this->assertNull($order->prepared_by);
    }

    public function test_pos_can_create_shipment_track_and_mark_order_delivered(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        foreach (['pos.access', 'orders.view', 'orders.update', 'pos.sales.refund'] as $name) {
            $actor->givePermissionTo(Permission::findOrCreate($name, 'staff'));
        }
        $drawer = Treasury::create([
            'name' => 'Ship Drawer', 'code' => 'DRW-SHIP-1', 'branch_id' => $branch->id,
            'type' => TreasuryType::PosDrawer, 'current_balance' => 0, 'is_active' => true,
        ]);
        PosShift::create([
            'shift_number' => 'SHIFT-SHIP', 'user_id' => $actor->id, 'branch_id' => $branch->id,
            'treasury_id' => $drawer->id, 'status' => PosShiftStatus::Open, 'opened_at' => now(), 'opening_cash' => 0,
        ]);
        $this->withToken($actor->createToken('pos', ['pos'])->plainTextToken);

        $variant = ProductVariant::factory()->create();
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id,
            'type' => StockMovementType::AdjustmentIn, 'affected_bucket' => StockBucket::OnHand,
            'quantity' => 5, 'created_by' => $actor->id,
        ]);

        $order = Order::factory()->create([
            'channel' => 'store',
            'allocated_branch_id' => $branch->id,
            'status' => OrderStatus::Confirmed,
            'payment_method' => 'cod',
            'payment_status' => 'unpaid',
            'subtotal' => 250,
            'grand_total' => 250,
        ]);
        OrderItem::factory()->for($order)->forVariant($variant)->create(['allocated_branch_id' => $branch->id, 'qty' => 2]);
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id,
            'type' => StockMovementType::Reserve, 'affected_bucket' => StockBucket::Reserved,
            'quantity' => 2, 'reference_type' => Order::class, 'reference_id' => $order->id,
            'created_by' => $actor->id,
        ]);

        $company = ShippingCompany::create([
            'name' => 'Aramex', 'code' => 'ARMX', 'is_active' => true,
            'tracking_url_template' => 'https://track.example.com/{awb}',
        ]);

        $this->getJson('/api/v1/pos/shipping-companies')
            ->assertOk()
            ->assertJsonPath('data.0.id', $company->id);

        $this->getJson("/api/v1/pos/incoming-orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.can_ship', true);

        $shipKey = $this->idemHeaders();
        $this->postJson("/api/v1/pos/incoming-orders/{$order->id}/shipments", [
            'shipping_company_id' => $company->id,
            'awb_number' => 'AWB-POS-100',
        ], $shipKey)->assertCreated()
            ->assertJsonPath('data.awb_number', 'AWB-POS-100')
            ->assertJsonPath('data.tracking_url', 'https://track.example.com/AWB-POS-100');

        // Idempotent replay
        $this->postJson("/api/v1/pos/incoming-orders/{$order->id}/shipments", [
            'shipping_company_id' => $company->id,
            'awb_number' => 'AWB-POS-100',
        ], $shipKey)->assertOk();

        $this->getJson("/api/v1/pos/incoming-orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'shipped')
            ->assertJsonPath('data.next_status', 'delivered')
            ->assertJsonPath('data.can_ship', false)
            ->assertJsonPath('data.shipments.0.awb_number', 'AWB-POS-100')
            ->assertJsonPath('data.shipments.0.tracking_url', 'https://track.example.com/AWB-POS-100');

        // Mark delivered from POS -> settles COD and unlocks direct branch return
        $this->postJson("/api/v1/pos/incoming-orders/{$order->id}/status", [
            'expected_status' => 'shipped',
            'status' => 'delivered',
        ], $this->idemHeaders())->assertOk()->assertJsonPath('data.status', 'delivered');

        $this->getJson("/api/v1/pos/incoming-orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.can_return', true)
            ->assertJsonPath('data.next_status', null);
    }
}
