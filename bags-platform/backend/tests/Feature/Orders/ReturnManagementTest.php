<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\City;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReturnRequest;
use App\Models\ShippingCompany;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ShipmentService;
use Database\Seeders\ReturnPermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * المرحلة 9 — إدارة المرتجعات (RMA): القاعدة الذهبية أن الإنشاء والاعتماد
 * لا يحرّكان مخزونًا؛ الاستلام والفحص وحده يضيف (سليم ← on_hand،
 * تالف ← damaged)، والرد المالي بعد الاستلام فقط، واكتمال إرجاع الطلب
 * ينقله إلى returned بلا ازدواج في الآثار.
 */
class ReturnManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    private string $staffToken;

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
        $this->staffToken = $this->staff->createToken('admin-token')->plainTextToken;
        $this->branch = Branch::factory()->create();
        $this->receiving = Location::factory()->create(['branch_id' => $this->branch->id, 'type' => 'receiving']);
    }

    private function purchasableVariant(float $price = 100): ProductVariant
    {
        $product = Product::factory()->create(['status' => 'published']);
        $variant = $product->variants()->first() ?? $product->variants()->create([
            'name_ar' => 'قياسي', 'sku' => 'SKU-'.uniqid(), 'retail_price' => $price, 'is_active' => true,
        ]);
        if ($variant->retail_price === null) {
            $variant->update(['retail_price' => $price]);
        }

        return $variant;
    }

    private function giveStock(ProductVariant $variant, float $qty): StockLevel
    {
        $location = Location::factory()->create(['branch_id' => $this->branch->id]);

        return StockLevel::query()->create([
            'branch_id' => $this->branch->id, 'location_id' => $location->id,
            'product_variant_id' => $variant->id, 'on_hand' => $qty, 'reserved' => 0,
        ]);
    }

    /** طلب عميل مُسلَّم فعليًا (شراء ← تأكيد ← شحن ← تسليم) بكمية وسعر محددين. */
    private function deliveredOrder(Customer $customer, ProductVariant $variant, int $qty): Order
    {
        $city = City::factory()->create(['shipping_cost' => 0]);
        $token = $customer->createToken('store-token')->plainTextToken;

        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id, 'qty' => $qty,
        ])->assertOk();
        Auth::forgetGuards();
        $orderId = $this->withToken($token)->postJson('/api/v1/store/checkout', [
            'city_id' => $city->id, 'shipping_address_line' => 'عنوان العميل', 'payment_method' => 'cod',
        ])->assertCreated()->json('data.id');

        $order = Order::findOrFail($orderId);
        $service = app(OrderService::class);
        $service->changeStatus($order, OrderStatus::Confirmed, $this->staff);
        $company = ShippingCompany::factory()->create(['code' => 'SHIPX-'.uniqid()]);
        app(ShipmentService::class)->createForOrder($order->fresh(), $company->id, null, $this->staff);
        $service->changeStatus($order->fresh(), OrderStatus::Delivered, $this->staff);

        return $order->fresh();
    }

    private function customerToken(Customer $customer): string
    {
        return $customer->createToken('store-token-'.uniqid())->plainTextToken;
    }

    /** إنشاء مرتجع من حساب العميل — يرجع بيانات الرد. */
    private function storeCreateReturn(Customer $customer, Order $order, int $orderItemId, float $qty, array $extra = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($this->customerToken($customer))->postJson('/api/v1/store/account/returns', array_merge([
            'order_id' => $order->id,
            'reason' => 'مقاس غير مناسب',
            'items' => [['order_item_id' => $orderItemId, 'requested_qty' => $qty]],
        ], $extra));
    }

    private function adminPost(string $url, array $data = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($this->staffToken)->postJson($url, $data);
    }

    /** رصيد مستوى الفرع العام (بلا موقع) الذي يستقبل المرتجعات. */
    private function branchLevel(ProductVariant $variant): ?StockLevel
    {
        return StockLevel::query()
            ->where('branch_id', $this->branch->id)
            ->where('location_id', $this->receiving->id)
            ->where('product_variant_id', $variant->id)
            ->first();
    }

    public function test_customer_creates_return_for_delivered_order_without_touching_stock(): void
    {
        $variant = $this->purchasableVariant(100);
        $level = $this->giveStock($variant, 10);
        $customer = Customer::factory()->create();
        $order = $this->deliveredOrder($customer, $variant, 3);
        $orderItem = $order->items->first();

        $response = $this->storeCreateReturn($customer, $order, $orderItem->id, 2);
        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.items.0.requested_qty', '2.0000')
            ->assertJsonPath('data.items.0.unit_refund_price', '100.0000');
        $this->assertStringStartsWith('RET-', (string) $response->json('data.return_number'));

        // القاعدة الذهبية (1): مجرد إنشاء الطلب لا يضيف رصيدًا ولا حركات
        $this->assertNull($this->branchLevel($variant));
        $this->assertSame('7.0000', (string) $level->fresh()->on_hand);
        $this->assertSame(0, StockMovement::query()->where('reference_type', 'return_request')->count());
    }

    public function test_create_rejected_for_non_delivered_or_foreign_or_over_quantity_or_expired(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 30);
        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();

        // طلب لم يُسلَّم بعد (pending)
        $city = City::factory()->create(['shipping_cost' => 0]);
        $token = $this->customerToken($customer);
        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id, 'qty' => 1,
        ])->assertOk();
        Auth::forgetGuards();
        $pendingId = $this->withToken($token)->postJson('/api/v1/store/checkout', [
            'city_id' => $city->id, 'shipping_address_line' => 'عنوان', 'payment_method' => 'cod',
        ])->assertCreated()->json('data.id');
        $pendingItem = Order::findOrFail($pendingId)->items->first();
        $this->storeCreateReturn($customer, Order::findOrFail($pendingId), $pendingItem->id, 1)
            ->assertUnprocessable();

        $order = $this->deliveredOrder($customer, $variant, 3);
        $orderItem = $order->items->first();

        // طلب يخص عميلًا آخر = 404
        $this->storeCreateReturn($other, $order, $orderItem->id, 1)->assertNotFound();

        // تجاوز الكمية المشتراة
        $this->storeCreateReturn($customer, $order, $orderItem->id, 4)->assertUnprocessable();

        // طلبان متتاليان: 2 ثم 2 يتجاوز 3، أما 2 ثم 1 فيمر
        $this->storeCreateReturn($customer, $order, $orderItem->id, 2)->assertCreated();
        $this->storeCreateReturn($customer, $order, $orderItem->id, 2)->assertUnprocessable();
        $this->storeCreateReturn($customer, $order, $orderItem->id, 1)->assertCreated();

        // انتهاء نافذة الاسترجاع (14 يومًا): تسليم قديم يُرفض
        $oldOrder = $this->deliveredOrder($customer, $variant, 1);
        OrderStatusHistory::query()
            ->where('order_id', $oldOrder->id)->where('to_status', 'delivered')
            ->update(['created_at' => now()->subDays(20)]);
        $this->storeCreateReturn($customer, $oldOrder, $oldOrder->items->first()->id, 1)
            ->assertUnprocessable();
    }

    public function test_approve_sets_branch_and_quantities_without_touching_stock(): void
    {
        $variant = $this->purchasableVariant(100);
        $level = $this->giveStock($variant, 10);
        $customer = Customer::factory()->create();
        $order = $this->deliveredOrder($customer, $variant, 3);
        $created = $this->storeCreateReturn($customer, $order, $order->items->first()->id, 3)->assertCreated();
        $returnId = $created->json('data.id');
        $returnItemId = $created->json('data.items.0.id');

        $this->adminPost("/api/v1/admin/returns/{$returnId}/approve", [
            'branch_id' => $this->branch->id,
            'items' => [['id' => $returnItemId, 'approved_qty' => 2]],
        ])->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.branch_id', $this->branch->id)
            ->assertJsonPath('data.items.0.approved_qty', '2.0000');

        // القاعدة الذهبية (2): الموافقة المبدئية لا تضيف أي رصيد إطلاقًا
        $this->assertNull($this->branchLevel($variant));
        $this->assertSame('7.0000', (string) $level->fresh()->on_hand);
        $this->assertSame(0, StockMovement::query()->where('reference_type', 'return_request')->count());
    }

    public function test_receive_moves_stock_good_and_damaged_and_computes_refund(): void
    {
        $variant = $this->purchasableVariant(100);
        $level = $this->giveStock($variant, 10);
        $customer = Customer::factory()->create();
        $order = $this->deliveredOrder($customer, $variant, 3);
        $created = $this->storeCreateReturn($customer, $order, $order->items->first()->id, 3)->assertCreated();
        $returnId = $created->json('data.id');
        $returnItemId = $created->json('data.items.0.id');
        $this->assertSame(3, (int) $variant->product->fresh()->sales_count);

        // الاستلام قبل الاعتماد ممنوع
        $this->adminPost("/api/v1/admin/returns/{$returnId}/receive", [
            'items' => [['id' => $returnItemId, 'received_good_qty' => 1, 'received_damaged_qty' => 0]],
        ])->assertUnprocessable();

        $this->adminPost("/api/v1/admin/returns/{$returnId}/approve", [
            'branch_id' => $this->branch->id,
        ])->assertOk();

        // استلام يتجاوز المعتمد (3) مرفوض
        $this->adminPost("/api/v1/admin/returns/{$returnId}/receive", [
            'items' => [['id' => $returnItemId, 'received_good_qty' => 3, 'received_damaged_qty' => 1]],
        ])->assertUnprocessable();

        $this->adminPost("/api/v1/admin/returns/{$returnId}/receive", [
            'items' => [['id' => $returnItemId, 'received_good_qty' => 2, 'received_damaged_qty' => 1]],
        ])->assertOk()
            ->assertJsonPath('data.status', 'received')
            ->assertJsonPath('data.total_refund_amount', '300.00');

        // السليم عاد متاحًا، والتالف محجوز في وعاء التالف، والفعلي زاد بالكل
        $branchLevel = $this->branchLevel($variant);
        $this->assertNotNull($branchLevel);
        $this->assertSame('3.0000', (string) $branchLevel->on_hand);
        $this->assertSame('1.0000', (string) $branchLevel->damaged);
        $this->assertEquals(2.0, (float) $branchLevel->available);
        // مستوى موقع البيع الأصلي لم يتغير بالاستلام
        $this->assertSame('7.0000', (string) $level->fresh()->on_hand);

        // الحركات: sale_return للسليم + sale_return وdamage للتالف — كلها بمرجع المرتجع
        $movements = StockMovement::query()->where('reference_type', 'return_request')->where('reference_id', $returnId)->get();
        $this->assertSame(3, $movements->count());
        $this->assertSame(2, $movements->where('type', 'sale_return')->count());
        $this->assertSame(1, $movements->where('type', 'damage')->count());
        // عدّاد المبيعات تراجع بالكمية المستلمة كلها
        $this->assertSame(0, (int) $variant->product->fresh()->sales_count);
    }

    public function test_refund_to_wallet_credits_customer_and_completes_order_return_without_double_effects(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 10);
        $customer = Customer::factory()->create();
        $order = $this->deliveredOrder($customer, $variant, 3);
        $created = $this->storeCreateReturn($customer, $order, $order->items->first()->id, 3, [
            'refund_method' => 'wallet',
        ])->assertCreated();
        $returnId = $created->json('data.id');
        $returnItemId = $created->json('data.items.0.id');

        // الرد قبل الاستلام ممنوع
        $this->adminPost("/api/v1/admin/returns/{$returnId}/refund")->assertUnprocessable();

        $this->adminPost("/api/v1/admin/returns/{$returnId}/approve", ['branch_id' => $this->branch->id])->assertOk();
        $this->adminPost("/api/v1/admin/returns/{$returnId}/receive", [
            'items' => [['id' => $returnItemId, 'received_good_qty' => 3, 'received_damaged_qty' => 0]],
        ])->assertOk();
        $stockAfterReceive = (string) $this->branchLevel($variant)->on_hand;

        $this->adminPost("/api/v1/admin/returns/{$returnId}/refund")->assertOk()
            ->assertJsonPath('data.status', 'refunded');

        // المحفظة زادت بقيمة المرتجع كاملة
        $this->assertSame('300.00', (string) $customer->fresh()->wallet_balance);
        // الطلب اكتمل إرجاعه فصارت حالته returned — بلا علم استرداد (الرد تم هنا)
        $order = $order->fresh();
        $this->assertSame(OrderStatus::Returned, $order->status);
        $this->assertFalse((bool) $order->requires_refund);
        // وبلا إعادة مخزون ثانية: رصيد ما بعد الرد يساوي رصيد ما بعد الاستلام
        $this->assertSame($stockAfterReceive, (string) $this->branchLevel($variant)->on_hand);
        $this->assertSame(1, StockMovement::query()->where('reference_type', 'return_request')->where('type', 'sale_return')->count());
    }

    public function test_partial_return_refund_keeps_order_delivered(): void
    {
        $variant = $this->purchasableVariant(50);
        $this->giveStock($variant, 10);
        $customer = Customer::factory()->create();
        $order = $this->deliveredOrder($customer, $variant, 3);
        $created = $this->storeCreateReturn($customer, $order, $order->items->first()->id, 1)->assertCreated();
        $returnId = $created->json('data.id');
        $returnItemId = $created->json('data.items.0.id');

        $this->adminPost("/api/v1/admin/returns/{$returnId}/approve", ['branch_id' => $this->branch->id])->assertOk();
        $this->adminPost("/api/v1/admin/returns/{$returnId}/receive", [
            'items' => [['id' => $returnItemId, 'received_good_qty' => 1, 'received_damaged_qty' => 0]],
        ])->assertOk();
        $this->adminPost("/api/v1/admin/returns/{$returnId}/refund", [
            'refund_method' => 'bank_transfer', 'refund_reference' => 'TRX-9988',
        ])->assertOk()
            ->assertJsonPath('data.status', 'refunded')
            ->assertJsonPath('data.refund_reference', 'TRX-9988');

        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
        // التحويل البنكي لا يمس محفظة العميل
        $this->assertSame('0.00', (string) $customer->fresh()->wallet_balance);
    }

    public function test_admin_manual_create_reject_releases_quota_and_delete_guard(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 10);
        $customer = Customer::factory()->create();
        $order = $this->deliveredOrder($customer, $variant, 2);
        $orderItemId = $order->items->first()->id;

        // إنشاء يدوي من خدمة العملاء يستنفد سقف البند كله
        $created = $this->adminPost('/api/v1/admin/returns', [
            'order_id' => $order->id,
            'reason' => 'طلب هاتفي من العميل',
            'items' => [['order_item_id' => $orderItemId, 'requested_qty' => 2]],
        ])->assertCreated();
        $returnId = $created->json('data.id');
        $this->storeCreateReturn($customer, $order, $orderItemId, 1)->assertUnprocessable();

        // الرفض عبر التعديل يحرر السقف: العميل يقدر يقدم طلبه بنفسه بعدها
        Auth::forgetGuards();
        $this->withToken($this->staffToken)->patchJson("/api/v1/admin/returns/{$returnId}", [
            'status' => 'rejected', 'admin_notes' => 'خارج سياسة الاسترجاع',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->storeCreateReturn($customer, $order, $orderItemId, 2)->assertCreated();

        // حذف مرتجع مستلم ممنوع
        $received = ReturnRequest::factory()->create(['status' => 'received']);
        $this->adminPost("/api/v1/admin/returns/{$received->id}/refund")->assertUnprocessable();
        Auth::forgetGuards();
        $this->withToken($this->staffToken)->deleteJson("/api/v1/admin/returns/{$received->id}")
            ->assertUnprocessable();
    }

    public function test_admin_returns_endpoints_require_permissions(): void
    {
        $plainStaff = User::factory()->create();
        $plainToken = $plainStaff->createToken('plain')->plainTextToken;

        Auth::forgetGuards();
        $this->withToken($plainToken)->getJson('/api/v1/admin/returns')->assertForbidden();

        Auth::forgetGuards();
        $this->withToken($this->staffToken)->getJson('/api/v1/admin/returns')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_unreceived_quantity_can_be_requested_again_and_order_waits_for_all_refunds(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 10);
        $customer = Customer::factory()->create();
        $order = $this->deliveredOrder($customer, $variant, 3);
        $itemId = $order->items->first()->id;
        $first = $this->storeCreateReturn($customer, $order, $itemId, 3)->assertCreated()->json('data');
        $this->adminPost("/api/v1/admin/returns/{$first['id']}/approve", ['branch_id' => $this->branch->id])->assertOk();
        $this->adminPost("/api/v1/admin/returns/{$first['id']}/receive", [
            'items' => [['id' => $first['items'][0]['id'], 'received_good_qty' => 1, 'received_damaged_qty' => 0]],
        ])->assertOk();
        $second = $this->storeCreateReturn($customer, $order, $itemId, 2)->assertCreated()->json('data');
        $this->adminPost("/api/v1/admin/returns/{$second['id']}/approve", ['branch_id' => $this->branch->id])->assertOk();
        $this->adminPost("/api/v1/admin/returns/{$second['id']}/receive", [
            'items' => [['id' => $second['items'][0]['id'], 'received_good_qty' => 2, 'received_damaged_qty' => 0]],
        ])->assertOk();

        $this->adminPost("/api/v1/admin/returns/{$first['id']}/refund", ['refund_method' => 'wallet'])->assertOk();
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
        $this->adminPost("/api/v1/admin/returns/{$second['id']}/refund", ['refund_method' => 'wallet'])->assertOk();

        $this->assertSame(OrderStatus::Returned, $order->fresh()->status);
        $this->assertSame('300.00', $customer->fresh()->wallet_balance);
    }

    public function test_bank_refund_without_reference_returns_422_without_financial_changes(): void
    {
        $return = ReturnRequest::factory()->create(['status' => 'received', 'total_refund_amount' => 100]);

        $this->adminPost("/api/v1/admin/returns/{$return->id}/refund", ['refund_method' => 'bank_transfer'])->assertUnprocessable();

        $this->assertDatabaseHas('return_requests', ['id' => $return->id, 'status' => 'received', 'refunded_at' => null]);
    }

    public function test_manual_order_return_with_received_rma_returns_422_without_double_stock(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 10);
        $customer = Customer::factory()->create();
        $order = $this->deliveredOrder($customer, $variant, 2);
        $return = $this->storeCreateReturn($customer, $order, $order->items->first()->id, 1)->assertCreated()->json('data');
        $this->adminPost("/api/v1/admin/returns/{$return['id']}/approve", ['branch_id' => $this->branch->id])->assertOk();
        $this->adminPost("/api/v1/admin/returns/{$return['id']}/receive", [
            'items' => [['id' => $return['items'][0]['id'], 'received_good_qty' => 1, 'received_damaged_qty' => 0]],
        ])->assertOk();
        $movementCount = StockMovement::count();
        Auth::forgetGuards();

        $this->withToken($this->staffToken)->patchJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => 'returned',
        ])->assertUnprocessable();

        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
        $this->assertSame($movementCount, StockMovement::count());
        $this->assertSame('1.0000', $this->branchLevel($variant)->on_hand);
    }

    public function test_refund_rolls_back_wallet_and_return_if_order_completion_fails(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 10);
        $customer = Customer::factory()->create();
        $order = $this->deliveredOrder($customer, $variant, 1);
        $return = $this->storeCreateReturn($customer, $order, $order->items->first()->id, 1)->assertCreated()->json('data');
        $this->adminPost("/api/v1/admin/returns/{$return['id']}/approve", ['branch_id' => $this->branch->id])->assertOk();
        $this->adminPost("/api/v1/admin/returns/{$return['id']}/receive", [
            'items' => [['id' => $return['items'][0]['id'], 'received_good_qty' => 1, 'received_damaged_qty' => 0]],
        ])->assertOk();
        $order->update(['status' => OrderStatus::Cancelled]);

        $this->adminPost("/api/v1/admin/returns/{$return['id']}/refund", ['refund_method' => 'wallet'])->assertUnprocessable();

        $this->assertSame('0.00', $customer->fresh()->wallet_balance);
        $this->assertDatabaseHas('return_requests', ['id' => $return['id'], 'status' => 'received', 'refunded_at' => null]);
    }
}
