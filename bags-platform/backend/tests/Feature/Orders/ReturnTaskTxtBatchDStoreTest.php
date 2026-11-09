<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\City;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReturnRequest;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ReturnService;
use App\Services\ShipmentService;
use Database\Seeders\ReturnPermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * task.txt الدفعة الرابعة (متجر): الرحلة الكاملة (29)، تغيير طريقة الرد،
 * إغلاق الفرع بين الموافقة والاستلام، وتكلفة فارغة بلا حركة بيع.
 * معاملات فقط بلا DDL.
 */
class ReturnTaskTxtBatchDStoreTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    private string $staffToken;

    private Branch $branch;

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
        Location::factory()->create(['branch_id' => $this->branch->id, 'type' => 'receiving']);
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

    private function giveStock(ProductVariant $variant, float $qty): void
    {
        $location = Location::factory()->create(['branch_id' => $this->branch->id]);
        \App\Models\StockLevel::query()->forceCreate([
            'branch_id' => $this->branch->id, 'location_id' => $location->id,
            'product_variant_id' => $variant->id, 'on_hand' => $qty, 'reserved' => 0,
        ]);
    }

    private function customerToken(Customer $customer): string
    {
        return $customer->createToken('store-token-'.uniqid())->plainTextToken;
    }

    private function deliveredOrder(Customer $customer, ProductVariant $variant, int $qty): Order
    {
        $city = City::factory()->create(['shipping_cost' => 0]);
        $token = $this->customerToken($customer);

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
        $company = \App\Models\ShippingCompany::factory()->create(['code' => 'SHIPX-'.uniqid()]);
        app(ShipmentService::class)->createForOrder($order->fresh(), $company->id, null, $this->staff);
        $service->changeStatus($order->fresh(), OrderStatus::Delivered, $this->staff);

        return $order->fresh();
    }

    private function adminPost(string $url, array $data = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($this->staffToken)->postJson($url, $data);
    }

    private function adminPatch(string $url, array $data = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($this->staffToken)->patchJson($url, $data);
    }

    /**
     * task.txt (29): رحلة مرتجع متجر كاملة عبر HTTP — إنشاء بصورة، موافقة،
     * استلام، رد للمحفظة، ثم تحقق الطرفين (العميل والإدارة) والمرفق والرصيد.
     */
    public function test_store_return_journey_end_to_end_via_apis(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 10);
        $customer = Customer::factory()->create();
        $order = $this->deliveredOrder($customer, $variant, 2);

        // 1) العميل ينشئ مرتجعًا مع صورة.
        Auth::forgetGuards();
        $created = $this->withToken($this->customerToken($customer))->post('/api/v1/store/account/returns', [
            'order_id' => $order->id,
            'reason' => 'مقاس غير مناسب',
            'items' => [[
                'order_item_id' => $order->items->first()->id, 'requested_qty' => 2,
                'attachments' => [UploadedFile::fake()->create('defect.jpg', 200, 'image/jpeg')],
            ]],
        ])->assertCreated()->json('data');
        $returnId = (int) $created['id'];
        $itemId = (int) $created['items'][0]['id'];

        // الصورة مخزنة تحت return_requests/pending/{uuid}/ وموجودة على القرص.
        $stored = ReturnRequest::findOrFail($returnId)->items()->firstOrFail()->attachments;
        $this->assertCount(1, $stored);
        $this->assertStringStartsWith('return_requests/pending/', $stored[0]);
        $this->assertTrue(Storage::disk('r2')->exists($stored[0]));

        // 2) الإدارة توافق وتستلم وترد للمحفظة.
        $this->adminPost("/api/v1/admin/returns/{$returnId}/approve", ['branch_id' => $this->branch->id])->assertOk();
        $this->adminPost("/api/v1/admin/returns/{$returnId}/receive", [
            'items' => [['id' => $itemId, 'received_good_qty' => 2, 'received_damaged_qty' => 0]],
        ])->assertOk();
        $this->adminPost("/api/v1/admin/returns/{$returnId}/refund", ['refund_method' => 'wallet'])->assertOk()
            ->assertJsonPath('data.status', 'refunded');

        // 3) العميل يرى المرتجع مستردًا ورصيده زاد ويفتح المرفق.
        Auth::forgetGuards();
        $token = $this->customerToken($customer);
        $this->withToken($token)->getJson("/api/v1/store/account/returns/{$returnId}")->assertOk()
            ->assertJsonPath('data.status', 'refunded');
        $this->assertSame('200.00', (string) $customer->fresh()->wallet_balance);
        Auth::forgetGuards();
        $this->withToken($token)->getJson("/api/v1/store/account/return-items/{$itemId}/attachments/0?signed=1")
            ->assertOk()->assertJsonStructure(['url']);

        // 4) الإدارة ترى التفاصيل الإدارية الكاملة.
        Auth::forgetGuards();
        $this->withToken($this->staffToken)->getJson("/api/v1/admin/returns/{$returnId}")->assertOk()
            ->assertJsonPath('data.status', 'refunded')
            ->assertJsonStructure(['data' => ['admin_notes', 'refund_reference']]);
    }

    /**
     * task.txt القسم 3: تغيير طريقة الرد مسموح في received ومرفوض بعد refunded.
     */
    public function test_refund_method_change_allowed_when_received_blocked_when_refunded(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 10);
        $customer = Customer::factory()->create();
        $order = $this->deliveredOrder($customer, $variant, 2);
        $created = $this->storeCreateReturn($customer, $order)->assertCreated();
        $returnId = (int) $created->json('data.id');
        $itemId = (int) $created->json('data.items.0.id');
        $this->adminPost("/api/v1/admin/returns/{$returnId}/approve", ['branch_id' => $this->branch->id])->assertOk();
        $this->adminPost("/api/v1/admin/returns/{$returnId}/receive", [
            'items' => [['id' => $itemId, 'received_good_qty' => 2, 'received_damaged_qty' => 0]],
        ])->assertOk();

        $this->adminPatch("/api/v1/admin/returns/{$returnId}", ['refund_method' => 'wallet'])->assertOk()
            ->assertJsonPath('data.refund_method', 'wallet');

        $this->adminPost("/api/v1/admin/returns/{$returnId}/refund", ['refund_method' => 'wallet'])->assertOk();
        $this->adminPatch("/api/v1/admin/returns/{$returnId}", ['refund_method' => 'cash'])
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'لا يمكن تغيير طريقة الرد بعد اكتمال الاسترداد.']);
    }

    /**
     * task.txt القسم 3: إغلاق/إيقاف فرع الاستلام بين الموافقة والاستلام يرفض
     * الاستلام برسالة الفرع غير النشط.
     */
    public function test_receive_rejected_when_branch_deactivated_after_approve(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 10);
        $customer = Customer::factory()->create();
        $order = $this->deliveredOrder($customer, $variant, 1);
        $created = $this->storeCreateReturn($customer, $order)->assertCreated();
        $returnId = (int) $created->json('data.id');
        $itemId = (int) $created->json('data.items.0.id');
        $this->adminPost("/api/v1/admin/returns/{$returnId}/approve", ['branch_id' => $this->branch->id])->assertOk();

        $this->branch->update(['is_active' => false]);

        $this->adminPost("/api/v1/admin/returns/{$returnId}/receive", [
            'items' => [['id' => $itemId, 'received_good_qty' => 1, 'received_damaged_qty' => 0]],
        ])->assertUnprocessable()
            ->assertJsonFragment(['message' => 'فرع الاستلام غير نشط.']);
        $this->assertSame('approved', ReturnRequest::findOrFail($returnId)->status->value);
    }

    /**
     * task.txt القسم 3: بند بلا أي حركة بيع (طلب مصنعي بلا تدفق بيع) يُستلم
     * بحركة إرجاع unit_cost فارغة دون كسر — ملاحظة: حركات المخزون append-only
     * يحميها trigger في قاعدة البيانات، فلا حذف ولا تعديل.
     */
    public function test_receive_without_sale_movement_records_null_unit_cost(): void
    {
        $variant = $this->purchasableVariant(100);
        $customer = Customer::factory()->create();
        $order = Order::factory()->create([
            'customer_id' => $customer->id, 'status' => OrderStatus::Delivered,
            'channel' => 'store', 'payment_status' => 'paid', 'payment_method' => 'cod',
            'allocated_branch_id' => $this->branch->id,
        ]);
        \App\Models\OrderStatusHistory::forceCreate([
            'order_id' => $order->id, 'from_status' => OrderStatus::Confirmed->value,
            'to_status' => OrderStatus::Delivered->value, 'changed_by_id' => $this->staff->id,
        ]);
        $orderItem = \App\Models\OrderItem::factory()->forVariant($variant)->create([
            'order_id' => $order->id, 'qty' => 1,
            'unit_price' => 100, 'discount_amount' => 0, 'tax_amount' => 0, 'line_total' => 100,
        ]);

        $returns = app(ReturnService::class);
        $return = $returns->createReturnRequest($order, [
            'reason' => 'بلا حركة بيع',
            'items' => [['order_item_id' => $orderItem->id, 'requested_qty' => 1]],
        ], $customer);
        $returns->approve($return, $this->staff, [
            'branch_id' => $this->branch->id,
            'items' => [['id' => $return->items->first()->id, 'approved_qty' => 1]],
        ]);
        $returns->receive($return->fresh(), $this->staff, [
            'items' => [['id' => $return->items->first()->id, 'received_good_qty' => 1, 'received_damaged_qty' => 0]],
        ]);

        // حركات استلام المرتجع تُسجَّل بمرجع طلب المرتجع (لا الطلب الأصلي).
        $movement = StockMovement::query()
            ->where('reference_type', (new \App\Models\ReturnRequest)->getMorphClass())
            ->where('reference_id', $return->id)
            ->where('type', \App\Enums\StockMovementType::SaleReturn)
            ->firstOrFail();
        $this->assertNull($movement->unit_cost);
    }

    private function storeCreateReturn(Customer $customer, Order $order): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($this->customerToken($customer))->postJson('/api/v1/store/account/returns', [
            'order_id' => $order->id,
            'reason' => 'مقاس غير مناسب',
            'items' => [['order_item_id' => $order->items->first()->id, 'requested_qty' => (int) $order->items->first()->qty]],
        ]);
    }
}
