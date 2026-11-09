<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Enums\ReturnStatus;
use App\Models\Branch;
use App\Models\City;
use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReturnRequest;
use App\Models\ReturnRequestItem;
use App\Models\ShippingCompany;
use App\Models\StockLevel;
use App\Models\TreasuryTransaction;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\ReturnService;
use App\Services\Orders\ShipmentService;
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

    /**
     * task.txt (4): بند كميته 3 وصافي قيمته 100.00 يُسترجع قطعةً قطعة على
     * ثلاثة مرتجعات منفصلة — مجموع refund_amount يساوي الصافي بالضبط،
     * والمرتجع الأخير يأخذ الفرق (cumulativeQty >= qty → netCents كاملًا).
     */
    public function test_three_sequential_receives_sum_exactly_to_net_cents(): void
    {
        $variant = $this->purchasableVariant(35);
        $this->giveStock($variant, 10);
        $customer = Customer::factory()->create();
        $order = $this->deliveredOrder($customer, $variant, 3);
        $order->items()->update(['unit_price' => 35, 'discount_amount' => 5, 'tax_amount' => 0]);

        $amounts = [];
        for ($i = 0; $i < 3; $i++) {
            $created = $this->storeCreateReturn($customer, $order, $order->items->first()->id, 1)->assertCreated();
            $returnId = (int) $created->json('data.id');
            $itemId = (int) $created->json('data.items.0.id');
            $this->adminPost("/api/v1/admin/returns/{$returnId}/approve", ['branch_id' => $this->branch->id])->assertOk();
            $received = $this->adminPost("/api/v1/admin/returns/{$returnId}/receive", [
                'items' => [['id' => $itemId, 'received_good_qty' => 1, 'received_damaged_qty' => 0]],
            ])->assertOk()->json('data');
            // ملاحظة: مورد البند الإداري لا يعرض refund_amount، فنقرأ إجمالي المرتجع (بند واحد).
            $amounts[] = $received['total_refund_amount'];
        }

        $this->assertSame(['33.33', '33.34', '33.33'], $amounts);
        $this->assertSame('100.00', number_format(array_sum(array_map('floatval', $amounts)), 2, '.', ''));
    }

    /**
     * task.txt (4) خاصية: لأي كمية وسعر وخصم وضريبة عشوائية، وأي تقسيم
     * عشوائي للكمية على عدة مرتجعات — المجموع يساوي الصافي تمامًا وكل جزء ≥ 0.
     */
    public function test_split_receives_sum_exactly_to_net_cents_property(): void
    {
        $returns = app(ReturnService::class);

        for ($trial = 0; $trial < 12; $trial++) {
            $qty = random_int(1, 5);
            $price = [10.5, 35.0, 99.99, 7.25][array_rand([10.5, 35.0, 99.99, 7.25])];
            $discount = round((float) random_int(0, (int) ($price * $qty * 0.3 * 100)) / 100, 2);
            $tax = random_int(0, 1) === 1 ? round($price * $qty * 0.14, 2) : 0.0;
            $net = round($price * $qty - $discount + $tax, 2);

            $customer = Customer::factory()->create();
            $variant = $this->purchasableVariant($price);
            $order = Order::factory()->create([
                'customer_id' => $customer->id,
                'status' => OrderStatus::Delivered,
                'channel' => 'store',
                'payment_status' => 'paid',
                'payment_method' => 'cod',
                'allocated_branch_id' => $this->branch->id,
            ]);
            OrderStatusHistory::create([
                'order_id' => $order->id, 'from_status' => OrderStatus::Confirmed->value,
                'to_status' => OrderStatus::Delivered->value, 'changed_by_id' => $this->staff->id,
            ]);
            $orderItem = OrderItem::factory()->forVariant($variant)->create([
                'order_id' => $order->id, 'qty' => $qty,
                'unit_price' => $price, 'discount_amount' => $discount,
                'tax_amount' => $tax, 'line_total' => $net,
            ]);

            // تقسيم عشوائي للكمية على 1..q مرتجعات.
            $remaining = $qty;
            $chunks = [];
            while ($remaining > 0) {
                $take = $remaining === 1 ? 1 : random_int(1, $remaining);
                $chunks[] = $take;
                $remaining -= $take;
            }

            $amounts = [];
            foreach ($chunks as $chunk) {
                $return = $returns->createReturnRequest($order, [
                    'reason' => 'فحص خاصية',
                    'items' => [['order_item_id' => $orderItem->id, 'requested_qty' => $chunk]],
                ], $customer);
                $returns->approve($return, $this->staff, [
                    'branch_id' => $this->branch->id,
                    'items' => [['id' => $return->items->first()->id, 'approved_qty' => $chunk]],
                ]);
                $received = $returns->receive($return->fresh(), $this->staff, [
                    'items' => [['id' => $return->items->first()->id, 'received_good_qty' => $chunk, 'received_damaged_qty' => 0]],
                ]);
                $amounts[] = (float) $received->items->first()->refund_amount;
            }

            foreach ($amounts as $amount) {
                $this->assertGreaterThanOrEqual(0, $amount, "trial {$trial}: جزء سالب");
            }
            $this->assertSame(
                number_format($net, 2, '.', ''),
                number_format(array_sum($amounts), 2, '.', ''),
                "trial {$trial}: q={$qty} price={$price} discount={$discount} tax={$tax}"
            );
        }
    }

    /**
     * task.txt (7): توثيق سلوك حالي بانتظار قرار صاحب المشروع — رد مرتجع
     * متجر بطريقة cash يُتم الرد دون تسجيل أي حركة خزينة.
     */
    public function test_cash_refund_records_no_treasury_movement_pending_owner_decision(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 10);
        $customer = Customer::factory()->create();
        $order = $this->deliveredOrder($customer, $variant, 2);
        $created = $this->storeCreateReturn($customer, $order, $order->items->first()->id, 2)->assertCreated();
        $returnId = (int) $created->json('data.id');
        $itemId = (int) $created->json('data.items.0.id');
        $this->adminPost("/api/v1/admin/returns/{$returnId}/approve", ['branch_id' => $this->branch->id])->assertOk();
        $this->adminPost("/api/v1/admin/returns/{$returnId}/receive", [
            'items' => [['id' => $itemId, 'received_good_qty' => 2, 'received_damaged_qty' => 0]],
        ])->assertOk();

        $this->assertSame(0, TreasuryTransaction::query()->count());
        $this->adminPost("/api/v1/admin/returns/{$returnId}/refund", ['refund_method' => 'cash'])->assertOk()
            ->assertJsonPath('data.status', 'refunded');

        // السلوك الحالي: لا حركة خزينة لطرق الرد غير المحفظة.
        $this->assertSame(0, TreasuryTransaction::query()->count());
        $this->assertSame('0.00', (string) $customer->fresh()->wallet_balance);
    }

    /**
     * task.txt (12): جدول انتقالات الحالة — كل إجراء من كل حالة.
     * المسموح: pending←approved، pending←rejected، pending/approved←cancelled،
     * approved←received، received←refunded، وحذف pending فقط.
     */
    public function test_return_status_transition_table(): void
    {
        $payloads = [
            'approve' => ['branch_id' => $this->branch->id],
            'cancel_via_update' => ['status' => 'cancelled'],
            'reject_via_update' => ['status' => 'rejected'],
            'change_method' => ['refund_method' => 'wallet'],
        ];

        $makeState = function (string $status): array {
            $order = Order::factory()->create(['allocated_branch_id' => $this->branch->id]);
            if (in_array($status, [ReturnStatus::Received->value, ReturnStatus::Refunded->value], true)) {
                $order->update(['payment_status' => 'paid', 'payment_method' => 'cod']);
            }
            $orderItem = OrderItem::factory()->create(['order_id' => $order->id, 'qty' => 5]);
            $return = ReturnRequest::factory()->create([
                'order_id' => $order->id,
                'status' => $status,
                // الاستلام يتطلب فرعًا محددًا؛ المعلَّق واقعيًا بلا فرع.
                'branch_id' => $status === ReturnStatus::Pending->value ? null : $this->branch->id,
            ]);
            $item = ReturnRequestItem::factory()->create([
                'return_request_id' => $return->id,
                'order_item_id' => $orderItem->id,
                'requested_qty' => 2,
                'approved_qty' => $status === ReturnStatus::Pending->value ? 0 : 2,
            ]);

            return [$return, $item];
        };

        // [الحالة => [الإجراء => الحالة المتوقعة بعده أو null للمنع]]
        $matrix = [
            ReturnStatus::Approved->value => ['approve' => null, 'receive' => ReturnStatus::Received->value, 'refund' => null, 'reject_via_update' => null, 'cancel_via_update' => ReturnStatus::Cancelled->value, 'delete' => null],
            ReturnStatus::Received->value => ['approve' => null, 'receive' => null, 'refund' => ReturnStatus::Refunded->value, 'reject_via_update' => null, 'cancel_via_update' => null, 'delete' => null, 'change_method' => ReturnStatus::Received->value],
            ReturnStatus::Refunded->value => ['approve' => null, 'receive' => null, 'refund' => null, 'reject_via_update' => null, 'cancel_via_update' => null, 'delete' => null, 'change_method' => null],
            ReturnStatus::Rejected->value => ['approve' => null, 'receive' => null, 'refund' => null, 'reject_via_update' => null, 'cancel_via_update' => null, 'delete' => null],
            ReturnStatus::Cancelled->value => ['approve' => null, 'receive' => null, 'refund' => null, 'reject_via_update' => null, 'cancel_via_update' => null, 'delete' => null],
            ReturnStatus::Pending->value => ['approve' => ReturnStatus::Approved->value, 'receive' => null, 'refund' => null, 'reject_via_update' => ReturnStatus::Rejected->value, 'cancel_via_update' => ReturnStatus::Cancelled->value, 'delete' => 'deleted'],
        ];

        foreach ($matrix as $state => $actions) {
            foreach ($actions as $action => $expected) {
                [$return, $item] = $makeState($state);
                $payload = match ($action) {
                    'approve' => $payloads['approve'],
                    'receive' => ['items' => [['id' => $item->id, 'received_good_qty' => 0, 'received_damaged_qty' => 0]]],
                    'refund' => ['refund_method' => 'cash'],
                    'delete' => [],
                    default => $payloads[$action],
                };

                if ($action === 'delete') {
                    $response = $this->adminDelete("/api/v1/admin/returns/{$return->id}");
                } elseif (in_array($action, ['reject_via_update', 'cancel_via_update', 'change_method'], true)) {
                    Auth::forgetGuards();
                    $response = $this->withToken($this->staffToken)->patchJson("/api/v1/admin/returns/{$return->id}", $payload);
                } else {
                    $response = $this->adminPost("/api/v1/admin/returns/{$return->id}/{$action}", $payload);
                }

                if ($expected === null) {
                    $response->assertUnprocessable();
                    $this->assertDatabaseHas('return_requests', ['id' => $return->id, 'status' => $state]);
                } elseif ($expected === 'deleted') {
                    $response->assertOk();
                    $this->assertDatabaseMissing('return_requests', ['id' => $return->id]);
                } else {
                    $response->assertOk()->assertJsonPath('data.status', $expected);
                }
            }
        }
    }

    private function adminDelete(string $url): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($this->staffToken)->deleteJson($url);
    }
}
