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
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\OrderAllocationService;
use App\Services\OrderService;
use Database\Seeders\SettingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * المرحلة 7 (7.1 + 7.2): جداول السلة/الطلبات، وخدمة التوزيع على الفروع
 * مع الحجز عبر دفتر المخزون، وبوابة تغيير الحالة OrderService::changeStatus.
 */
class OrderAllocationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class); // مفاتيح الترقيم لحركات الدفتر
        $this->staff = User::factory()->create();
    }

    // ---------- 7.1: الأساسيات ----------

    public function test_guest_cart_gets_token_and_holds_items(): void
    {
        $cart = Cart::create([]);
        $this->assertNotEmpty($cart->cart_token);

        $item = CartItem::factory()->for($cart)->create(['qty' => 2.5]);
        $this->assertSame('2.5000', $cart->items->first()->qty);
        $this->assertSame($item->id, $cart->items->first()->id);

        // صنف واحد في السلة = صف واحد (الكميات تتراكم عليه)
        try {
            DB::transaction(function () use ($cart, $item): void {
                CartItem::create([
                    'cart_id' => $cart->id,
                    'product_variant_id' => $item->product_variant_id,
                    'qty' => 1,
                ]);
            });
            $this->fail('تكرار الصنف في نفس السلة يجب أن يُرفض بقيد فريد.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_order_casts_status_and_soft_deletes(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);
        $this->assertSame(OrderStatus::Pending, $order->status);
        $order->delete();
        $this->assertSoftDeleted('orders', ['id' => $order->id]);
    }

    // ---------- 7.2: التوزيع ----------

    public function test_full_branch_allocation_prefers_customer_city(): void
    {
        $variant = ProductVariant::factory()->create();
        $otherCity = Branch::factory()->create(['city' => 'الجيزة']);
        $sameCity = Branch::factory()->create(['city' => 'القاهرة']);
        $this->giveStock($variant, $otherCity, 10);
        $this->giveStock($variant, $sameCity, 10);

        $city = City::factory()->create(['name_ar' => 'القاهرة']);
        $order = $this->makeOrder([[$variant, 3]], cityId: $city->id);

        $allocated = app(OrderAllocationService::class)->allocate($order, performedBy: $this->staff->id);

        $this->assertSame($sameCity->id, $allocated->allocated_branch_id);
        $this->assertSame($sameCity->id, $allocated->items->first()->allocated_branch_id);
        $this->assertSame('3.0000', (string) $this->level($variant->id, $sameCity->id)->reserved);
        $this->assertSame('0.0000', (string) $this->level($variant->id, $otherCity->id)->reserved);

        $movement = StockMovement::query()->where('type', StockMovementType::Reserve)->sole();
        $this->assertSame('order', $movement->reference_type);
        $this->assertSame($order->id, $movement->reference_id);
    }

    public function test_forced_branch_allocation_and_its_failures_roll_back(): void
    {
        $variant = ProductVariant::factory()->create();
        $rich = Branch::factory()->create();
        $poor = Branch::factory()->create();
        $inactive = Branch::factory()->create(['is_active' => false]);
        $this->giveStock($variant, $rich, 50);
        $this->giveStock($variant, $poor, 2);
        $this->giveStock($variant, $inactive, 50);

        $service = app(OrderAllocationService::class);

        $forcedOrder = $this->makeOrder([[$variant, 5]]);
        $allocated = $service->allocate($forcedOrder, forcedBranchId: $rich->id, performedBy: $this->staff->id);
        $this->assertSame($rich->id, $allocated->allocated_branch_id);
        $this->assertSame('5.0000', (string) $this->level($variant->id, $rich->id)->reserved);

        // فرع مُجبر لا يغطي الكمية: فشل بلا أي حجز متسرب
        $shortOrder = $this->makeOrder([[$variant, 5]]);
        try {
            $service->allocate($shortOrder, forcedBranchId: $poor->id, performedBy: $this->staff->id);
            $this->fail('فرع لا يغطي الكمية يجب أن يفشل.');
        } catch (BusinessException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('0.0000', (string) $this->level($variant->id, $poor->id)->reserved);
        $this->assertNull($shortOrder->fresh()->allocated_branch_id);

        // فرع معطّل لا يُفرض
        $inactiveOrder = $this->makeOrder([[$variant, 1]]);
        $this->expectException(BusinessException::class);
        $service->allocate($inactiveOrder, forcedBranchId: $inactive->id, performedBy: $this->staff->id);
    }

    public function test_split_allocation_assigns_each_item_to_its_best_branch(): void
    {
        $variantA = ProductVariant::factory()->create();
        $variantB = ProductVariant::factory()->create();
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $this->giveStock($variantA, $branchA, 10);
        $this->giveStock($variantB, $branchB, 4);
        $this->giveStock($variantB, $branchA, 1); // فرع A لا يغطي B وحده بالكامل

        $order = $this->makeOrder([[$variantA, 6], [$variantB, 3]]);
        $allocated = app(OrderAllocationService::class)->allocate($order, performedBy: $this->staff->id);

        $this->assertNull($allocated->allocated_branch_id);
        $byVariant = $allocated->items->keyBy('product_variant_id');
        $this->assertSame($branchA->id, $byVariant[$variantA->id]->allocated_branch_id);
        $this->assertSame($branchB->id, $byVariant[$variantB->id]->allocated_branch_id);
        $this->assertSame('6.0000', (string) $this->level($variantA->id, $branchA->id)->reserved);
        $this->assertSame('3.0000', (string) $this->level($variantB->id, $branchB->id)->reserved);
    }

    public function test_insufficient_stock_anywhere_rolls_back_all_reservations(): void
    {
        $coverable = ProductVariant::factory()->create();
        $missing = ProductVariant::factory()->create();
        $branch = Branch::factory()->create();
        $this->giveStock($coverable, $branch, 10);

        $order = $this->makeOrder([[$coverable, 4], [$missing, 2]]);

        try {
            app(OrderAllocationService::class)->allocate($order, performedBy: $this->staff->id);
            $this->fail('صنف بلا رصيد يجب أن يُفشل التوزيع كله.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString($order->items->last()->sku_snapshot, $e->getMessage());
        }

        $this->assertSame('0.0000', (string) $this->level($coverable->id, $branch->id)->reserved);
        $this->assertSame(0, StockMovement::query()->where('type', StockMovementType::Reserve)->count());
        $this->assertNull($order->fresh()->allocated_branch_id);
    }

    public function test_double_allocation_is_rejected(): void
    {
        $variant = ProductVariant::factory()->create();
        $branch = Branch::factory()->create();
        $this->giveStock($variant, $branch, 10);
        $order = $this->makeOrder([[$variant, 2]]);

        $service = app(OrderAllocationService::class);
        $service->allocate($order, performedBy: $this->staff->id);

        $this->expectException(BusinessException::class);
        $service->allocate($order->fresh(), performedBy: $this->staff->id);
    }

    // ---------- بوابة تغيير الحالة ----------

    public function test_change_status_to_confirmed_records_history_and_allocates(): void
    {
        $variant = ProductVariant::factory()->create();
        $branch = Branch::factory()->create();
        $this->giveStock($variant, $branch, 10);
        $order = $this->makeOrder([[$variant, 2]]);

        $service = app(OrderService::class);
        $confirmed = $service->changeStatus($order, OrderStatus::Confirmed, $this->staff, 'اعتماد من الإدارة');

        $this->assertSame(OrderStatus::Confirmed, $confirmed->status);
        $this->assertSame($branch->id, $confirmed->allocated_branch_id);
        $this->assertSame('2.0000', (string) $this->level($variant->id, $branch->id)->reserved);

        $history = $confirmed->statusHistories->first();
        $this->assertSame(OrderStatus::Pending, $history->from_status);
        $this->assertSame(OrderStatus::Confirmed, $history->to_status);
        $this->assertSame(User::class, $history->changed_by_type);
        $this->assertSame($this->staff->id, $history->changed_by_id);
        $this->assertSame('اعتماد من الإدارة', $history->notes);

        // انتقال لاحق لا يعيد التوزيع ولا يكتب إلا سجلًا جديدًا
        $processing = $service->changeStatus($confirmed, 'processing', $this->staff);
        $this->assertSame(OrderStatus::Processing, $processing->status);
        $this->assertSame(2, $processing->statusHistories()->count());
        $this->assertSame('2.0000', (string) $this->level($variant->id, $branch->id)->reserved);
    }

    public function test_change_status_records_customer_actor_without_allocating(): void
    {
        $customer = Customer::factory()->create();
        // طلب مؤكد مباشرة (بلا توزيع) — الانتقال لـ processing قانوني ولا أثر مخزوني له
        $order = $this->makeOrder([], customerId: $customer->id);
        $order->update(['status' => OrderStatus::Confirmed]);

        $result = app(OrderService::class)->changeStatus($order, OrderStatus::Processing, $customer);

        $history = $result->statusHistories->first();
        $this->assertSame(Customer::class, $history->changed_by_type);
        $this->assertSame($customer->id, $history->changed_by_id);
    }

    // ---------- helpers ----------

    private function giveStock(ProductVariant $variant, Branch $branch, float|int $qty): void
    {
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id,
            'branch_id' => $branch->id,
            'type' => StockMovementType::AdjustmentIn,
            'affected_bucket' => StockBucket::OnHand,
            'quantity' => $qty,
            'created_by' => $this->staff->id,
        ]);
    }

    /** @param array<int, array{0: ProductVariant, 1: float|int}> $lines */
    private function makeOrder(array $lines, ?int $cityId = null, ?int $customerId = null): Order
    {
        $order = Order::factory()->create([
            'city_id' => $cityId,
            'customer_id' => $customerId,
        ]);
        foreach ($lines as [$variant, $qty]) {
            OrderItem::factory()->forVariant($variant)->create([
                'order_id' => $order->id,
                'qty' => $qty,
                'unit_price' => 100,
                'line_total' => 100 * $qty,
            ]);
        }

        return $order->fresh(['items']);
    }

    private function level(int $variantId, int $branchId): StockLevel
    {
        return StockLevel::query()
            ->where('product_variant_id', $variantId)
            ->where('branch_id', $branchId)
            ->whereNull('location_id')
            ->firstOrFail();
    }
}
