<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\City;
use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReturnRequest;
use App\Models\ShippingCompany;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ShipmentService;
use Database\Seeders\ReturnPermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\RunsConcurrentAuditJobs;
use Tests\TestCase;

/**
 * تغطية task.txt للمرتجعات: التزامن على الرد (5)، مصفوفة طرق الرد (6)،
 * وخصوصية الـStore Resource (27).
 */
class ReturnTaskTxtCoverageTest extends TestCase
{
    use DatabaseMigrations;
    use RunsConcurrentAuditJobs;

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

    protected function tearDown(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true]);
        parent::tearDown();
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

        return StockLevel::query()->forceCreate([
            'branch_id' => $this->branch->id, 'location_id' => $location->id,
            'product_variant_id' => $variant->id, 'on_hand' => $qty, 'reserved' => 0,
        ]);
    }

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

    /** دورة كاملة حتى الاستلام لمرتجع محفظة: ترجع [returnId]. */
    private function receivedWalletReturn(Customer $customer, Order $order, int $orderItemId, float $qty): int
    {
        $created = $this->storeCreateReturn($customer, $order, $orderItemId, $qty, ['refund_method' => 'wallet'])->assertCreated();
        $returnId = (int) $created->json('data.id');
        $returnItemId = (int) $created->json('data.items.0.id');
        $this->adminPost("/api/v1/admin/returns/{$returnId}/approve", ['branch_id' => $this->branch->id])->assertOk();
        $this->adminPost("/api/v1/admin/returns/{$returnId}/receive", [
            'items' => [['id' => $returnItemId, 'received_good_qty' => $qty, 'received_damaged_qty' => 0]],
        ])->assertOk();

        return $returnId;
    }

    /**
     * task.txt (5): طلبان متزامنان POST /admin/returns/{id}/refund بطريقة wallet.
     * واحد ينجح والثاني يُرفض برسالة ما بعد الاستلام، وحركة محفظة واحدة فقط.
     */
    public function test_concurrent_wallet_refunds_apply_only_once(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 10);
        $customer = Customer::factory()->create();
        $order = $this->deliveredOrder($customer, $variant, 3);
        $returnId = $this->receivedWalletReturn($customer, $order, $order->items->first()->id, 3);

        $job = $this->httpJob($this->staffToken, 'POST', "/api/v1/admin/returns/{$returnId}/refund", ['refund_method' => 'wallet']);
        $results = $this->concurrentJobs([$job, $job]);

        $this->assertEqualsCanonicalizing([200, 422], array_column($results, 'status'));
        $loser = collect($results)->firstWhere('status', 422);
        $this->assertStringContainsString('بعد استلامه', (string) json_encode($loser['body'], JSON_UNESCAPED_UNICODE));

        $this->assertSame(1, CustomerWalletTransaction::query()
            ->where('reference_type', 'return_request')->where('reference_id', $returnId)->where('type', 'return_refund')->count());
        $this->assertSame('300.00', (string) $customer->fresh()->wallet_balance);
        $this->assertSame('refunded', ReturnRequest::findOrFail($returnId)->status->value);
    }

    /**
     * task.txt (6): كل توليفة مرفوضة من طريقة الرد × حالة الدفع ترجع رسالتها
     * المحددة في الكود، وبدون أي تغيير في الحالة أو المحفظة.
     */
    public function test_refund_rejects_invalid_method_matrix_without_side_effects(): void
    {
        // (أ) طلب زائر (customer_id = null) + wallet
        $guest = ReturnRequest::factory()->create(['status' => 'received', 'customer_id' => null, 'total_refund_amount' => 50]);
        $guest->order()->update(['payment_status' => 'paid', 'payment_method' => 'cod']);
        $this->adminPost("/api/v1/admin/returns/{$guest->id}/refund", ['refund_method' => 'wallet'])
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'الاسترداد إلى المحفظة يتطلب حساب عميل مسجل؛ اختر طريقة أخرى لطلبات الزوار.']);

        // (ب) طلب مدفوع بالمحفظة + طريقة غير wallet
        $walletPaid = ReturnRequest::factory()->create(['status' => 'received', 'total_refund_amount' => 40]);
        $walletPaid->order()->update(['payment_status' => 'paid', 'payment_method' => 'wallet']);
        $this->adminPost("/api/v1/admin/returns/{$walletPaid->id}/refund", ['refund_method' => 'bank_transfer', 'refund_reference' => 'REF-1'])
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'طلب مدفوع بالمحفظة يُرد إلى المحفظة فقط.']);

        // (ج) طلب غير مدفوع + wallet
        $unpaid = ReturnRequest::factory()->create(['status' => 'received', 'total_refund_amount' => 30]);
        $unpaid->order()->update(['payment_status' => 'unpaid', 'payment_method' => 'cod']);
        $this->adminPost("/api/v1/admin/returns/{$unpaid->id}/refund", ['refund_method' => 'wallet'])
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'لا يمكن رد مبلغ نقدي أو إلى المحفظة لطلب غير مدفوع. يُقيَّد المرتجع كتخفيض للمستحق حتى يُبنى مسار إشعار دائن.']);

        // (د) طريقة null في الطلب وفي المخزَّن
        $noMethod = ReturnRequest::factory()->create(['status' => 'received', 'refund_method' => null, 'total_refund_amount' => 20]);
        $noMethod->order()->update(['payment_status' => 'paid', 'payment_method' => 'cod']);
        $this->adminPost("/api/v1/admin/returns/{$noMethod->id}/refund")
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'حدد طريقة رد المبلغ قبل إتمام الاسترداد.']);

        // (هـ) original_payment بمرجع مسافات فقط
        $blankRef = ReturnRequest::factory()->create(['status' => 'received', 'total_refund_amount' => 25]);
        $blankRef->order()->update(['payment_status' => 'paid', 'payment_method' => 'cod']);
        $this->adminPost("/api/v1/admin/returns/{$blankRef->id}/refund", ['refund_method' => 'original_payment', 'refund_reference' => '   '])
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'مرجع التحويل أو بوابة الدفع مطلوب لإتمام الاسترداد.']);

        // (و) مرجع مكرر بحروف كبيرة ومسافات (" ABC " مقابل "abc") → 409
        $first = ReturnRequest::factory()->create(['status' => 'refunded', 'refund_method' => 'bank_transfer', 'refund_reference' => 'abc-77', 'total_refund_amount' => 10]);
        $first->order()->update(['payment_status' => 'paid', 'payment_method' => 'cod']);
        $second = ReturnRequest::factory()->create(['status' => 'received', 'total_refund_amount' => 10]);
        $second->order()->update(['payment_status' => 'paid', 'payment_method' => 'cod']);
        $this->adminPost("/api/v1/admin/returns/{$second->id}/refund", ['refund_method' => 'bank_transfer', 'refund_reference' => ' ABC-77 '])
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'مرجع الرد مسجّل سابقًا.']);

        foreach ([$guest, $walletPaid, $unpaid, $noMethod, $blankRef, $second] as $return) {
            $this->assertDatabaseHas('return_requests', ['id' => $return->id, 'status' => 'received', 'refunded_at' => null]);
        }
        $this->assertSame(0, CustomerWalletTransaction::query()->count());
    }

    /**
     * task.txt (27): استجابة المتجر لا تعرض admin_notes ولا approved_by ولا
     * received_by ولا refunded_by ولا customer_name، والمرفقات روابط API لا مسارات R2.
     */
    public function test_store_return_responses_hide_admin_fields_and_link_attachments(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 10);
        $customer = Customer::factory()->create();
        $order = $this->deliveredOrder($customer, $variant, 2);
        $returnId = $this->receivedWalletReturn($customer, $order, $order->items->first()->id, 2);

        $item = ReturnRequest::findOrFail($returnId)->items()->firstOrFail();
        $item->update(['attachments' => ['return_requests/pending/uuid-1/photo-a.jpg', 'return_requests/pending/uuid-1/photo-b.jpg']]);
        $this->adminPost("/api/v1/admin/returns/{$returnId}/refund", ['refund_method' => 'wallet'])->assertOk();

        Auth::forgetGuards();
        $token = $this->customerToken($customer);
        $show = $this->withToken($token)->getJson("/api/v1/store/account/returns/{$returnId}")->assertOk()->json('data');
        Auth::forgetGuards();
        $index = $this->withToken($token)->getJson('/api/v1/store/account/returns')->assertOk()->json('data');

        foreach (['admin_notes', 'approved_by', 'received_by', 'refunded_by', 'customer_name'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $show, "Store show must not expose {$hidden}");
            $this->assertArrayNotHasKey($hidden, $index[0], "Store index must not expose {$hidden}");
        }

        $this->assertCount(2, $show['items'][0]['attachments']);
        foreach ($show['items'][0]['attachments'] as $i => $link) {
            $this->assertSame(
                url("/api/v1/store/account/return-items/{$item->id}/attachments/{$i}"),
                $link
            );
            $this->assertStringNotContainsString('return_requests/', (string) $link);
        }
    }
}
