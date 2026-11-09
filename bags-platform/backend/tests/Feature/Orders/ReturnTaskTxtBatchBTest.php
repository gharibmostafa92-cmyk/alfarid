<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\Orders\ReturnStatus;
use App\Models\Branch;
use App\Models\City;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\ReturnService;
use App\Services\Shared\SettingService;
use App\Services\Orders\ShipmentService;
use Database\Seeders\ReturnPermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\RunsConcurrentAuditJobs;
use Tests\TestCase;

/**
 * task.txt الدفعة الثانية (متجر): قيود الإنشاء (13)، نافذة الإرجاع (14)،
 * المرفقات (15)، التنزيل (16)، عزل العملاء (10)، عزل الفروع (9).
 */
class ReturnTaskTxtBatchBTest extends TestCase
{
    // ملاحظة: معاملات فقط بلا DDL، لأن DatabaseMigrations تُرجع آخر دفعة
    // بعد كل اختبار وdown() مستخدم النظام يفشل عند وجود حركات تشير إليه.
    use LazilyRefreshDatabase;
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
        $service->changeStatus($order, \App\Enums\OrderStatus::Confirmed, $this->staff);
        $company = \App\Models\ShippingCompany::factory()->create(['code' => 'SHIPX-'.uniqid()]);
        app(ShipmentService::class)->createForOrder($order->fresh(), $company->id, null, $this->staff);
        $service->changeStatus($order->fresh(), \App\Enums\OrderStatus::Delivered, $this->staff);

        return $order->fresh();
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

    /**
     * task.txt (13): قيود إنشاء مرتجع المتجر — حالة الطلب، والقناة، والبنود،
     * والكمية التراكمية (معتمد/مستلم)، وقاعدة distinct، وتجميع الخدمة للمكرر.
     */
    public function test_store_create_rejects_guards_and_tracks_cumulative_quotas(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 30);
        $customer = Customer::factory()->create();
        $order = $this->deliveredOrder($customer, $variant, 5);
        $orderItemId = $order->items->first()->id;

        // طلب قناته pos يُرفض من مسار المتجر.
        $posOrder = Order::factory()->create([
            'customer_id' => $customer->id, 'status' => \App\Enums\OrderStatus::Delivered,
            'channel' => 'pos', 'allocated_branch_id' => $this->branch->id,
        ]);
        $posItem = \App\Models\OrderItem::factory()->create(['order_id' => $posOrder->id, 'qty' => 2]);
        \App\Models\OrderStatusHistory::create([
            'order_id' => $posOrder->id, 'from_status' => \App\Enums\OrderStatus::Confirmed->value,
            'to_status' => \App\Enums\OrderStatus::Delivered->value, 'changed_by_id' => $this->staff->id,
        ]);
        $this->storeCreateReturn($customer, $posOrder, $posItem->id, 1)
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'مرتجعات نقطة البيع تُنفَّذ من مسار POS لمنع تكرار الرد بين مسارين.']);

        // بند من طلب آخر.
        $otherItem = \App\Models\OrderItem::factory()->create(['qty' => 1]);
        $this->storeCreateReturn($customer, $order, $otherItem->id, 1)
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'أحد البنود المطلوب إرجاعها لا يتبع هذا الطلب.']);

        // مرتجع سابق approved بكمية معتمدة أقل: السقف يحسب المعتمد لا المطلوب.
        $first = $this->storeCreateReturn($customer, $order, $orderItemId, 4)->assertCreated()->json('data');
        $this->adminPost("/api/v1/admin/returns/{$first['id']}/approve", [
            'branch_id' => $this->branch->id,
            'items' => [['id' => $first['items'][0]['id'], 'approved_qty' => 2]],
        ])->assertOk();
        // المعتمد 2 من 5: طلب 4 يتجاوز، وطلب 3 يمر.
        $this->storeCreateReturn($customer, $order, $orderItemId, 4)->assertUnprocessable();
        $second = $this->storeCreateReturn($customer, $order, $orderItemId, 3)->assertCreated()->json('data');

        // الاستلام يحسب المستلم (سليم + تالف) لا المطلوب.
        $this->adminPost("/api/v1/admin/returns/{$second['id']}/approve", ['branch_id' => $this->branch->id])->assertOk();
        $this->adminPost("/api/v1/admin/returns/{$second['id']}/receive", [
            'items' => [['id' => $second['items'][0]['id'], 'received_good_qty' => 2, 'received_damaged_qty' => 0]],
        ])->assertOk();
        // المستلم 2 + المعتمد 2 = 4 من 5: طلب 2 يتجاوز، وطلب 1 يمر.
        $this->storeCreateReturn($customer, $order, $orderItemId, 2)->assertUnprocessable();
        $this->storeCreateReturn($customer, $order, $orderItemId, 1)->assertCreated();
    }

    /**
     * task.txt (13): items مكررة لنفس البند — الـAPI يرفض 422 (distinct)،
     * أما الخدمة مباشرة فتجمع الكميات في بند واحد.
     */
    public function test_duplicate_lines_rejected_by_api_but_aggregated_by_service(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 10);
        $customer = Customer::factory()->create();
        $order = $this->deliveredOrder($customer, $variant, 5);
        $orderItemId = $order->items->first()->id;

        Auth::forgetGuards();
        $this->withToken($this->customerToken($customer))->postJson('/api/v1/store/account/returns', [
            'order_id' => $order->id,
            'reason' => 'مكرر',
            'items' => [
                ['order_item_id' => $orderItemId, 'requested_qty' => 1],
                ['order_item_id' => $orderItemId, 'requested_qty' => 1],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.order_item_id');

        $return = app(ReturnService::class)->createReturnRequest($order, [
            'reason' => 'مكرر',
            'items' => [
                ['order_item_id' => $orderItemId, 'requested_qty' => 1],
                ['order_item_id' => $orderItemId, 'requested_qty' => 1],
            ],
        ], $customer);

        $this->assertCount(1, $return->items);
        $this->assertSame('2.0000', (string) $return->items->first()->requested_qty);
    }

    private function setReturnWindowDays(int $days): void
    {
        DB::table('settings')->where('key', 'return_window_days')->update(['value' => (string) $days]);
        Cache::flush();
        app(SettingService::class)->clearCache();
    }

    private function backdateDelivery(Order $order, \DateTimeInterface $deliveredAt): void
    {
        \App\Models\OrderStatusHistory::query()->where('order_id', $order->id)
            ->where('to_status', \App\Enums\OrderStatus::Delivered->value)
            ->update(['created_at' => $deliveredAt]);
    }

    /**
     * task.txt (14): حدود نافذة الإرجاع — صفر يرفض الكل، وسالب يسمح دائمًا،
     * و±ثانية حول الحد، وبدون سجل تسليم، وبأحدث سجل تسليم عند التكرار.
     */
    public function test_return_window_boundaries(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 30);
        $this->setReturnWindowDays(14);

        $makeDelivered = function () use ($variant): array {
            $customer = Customer::factory()->create();
            $order = $this->deliveredOrder($customer, $variant, 1);

            return [$customer, $order, $order->items->first()->id];
        };

        // صفر: الإرجاع متوقف برسالة مخصصة حتى لطلب اليوم نفسه.
        $this->setReturnWindowDays(0);
        [$customer, $order, $itemId] = $makeDelivered();
        $this->storeCreateReturn($customer, $order, $itemId, 1)
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'الإرجاع متوقف من إعدادات المتجر.']);

        // سالب: يسمح حتى لطلب قديم جدًا.
        $this->setReturnWindowDays(-1);
        [$customer, $order, $itemId] = $makeDelivered();
        $this->backdateDelivery($order, now()->subDays(100));
        $this->storeCreateReturn($customer, $order, $itemId, 1)->assertCreated();

        // الحد بدقة الثانية: deliveredAt + النافذة يجب أن يكون في المستقبل.
        // +ثانية (13 يومًا و23:59:59) مقبول، و−ثانية (14 يومًا وثانية) مرفوض.
        $this->setReturnWindowDays(14);
        [$customer, $order, $itemId] = $makeDelivered();
        $this->backdateDelivery($order, now()->subDays(14)->addSecond());
        $this->storeCreateReturn($customer, $order, $itemId, 1)->assertCreated();

        [$customer, $order, $itemId] = $makeDelivered();
        $this->backdateDelivery($order, now()->subDays(14)->subSecond());
        $this->storeCreateReturn($customer, $order, $itemId, 1)
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'انتهت فترة الاسترجاع المسموحة (14 يومًا من التسليم) لهذا الطلب.']);

        [$customer, $order, $itemId] = $makeDelivered();
        $this->backdateDelivery($order, now()->subDays(15));
        $this->storeCreateReturn($customer, $order, $itemId, 1)->assertUnprocessable();

        // بلا سجل تسليم إطلاقًا.
        $customer = Customer::factory()->create();
        $ghost = Order::factory()->create([
            'customer_id' => $customer->id, 'status' => \App\Enums\OrderStatus::Delivered,
            'channel' => 'store', 'payment_status' => 'paid', 'payment_method' => 'cod',
            'allocated_branch_id' => $this->branch->id,
        ]);
        $ghostItem = \App\Models\OrderItem::factory()->create(['order_id' => $ghost->id, 'qty' => 1]);
        $this->storeCreateReturn($customer, $ghost, $ghostItem->id, 1)
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'لا يوجد سجل تسليم موثوق لحساب فترة الاسترجاع.']);

        // تسليم مزدوج: يُعتمد الأحدث (توثيق سلوك حالي بانتظار قرار صاحب المشروع).
        // ملاحظة: created_at ليس fillable في OrderStatusHistory لذا يُضبط باستعلام مباشر.
        [$customer, $order, $itemId] = $makeDelivered();
        $this->backdateDelivery($order, now()->subDays(20));
        $older = \App\Models\OrderStatusHistory::forceCreate([
            'order_id' => $order->id, 'from_status' => \App\Enums\OrderStatus::Delivered->value,
            'to_status' => \App\Enums\OrderStatus::Delivered->value, 'changed_by_id' => $this->staff->id,
        ]);
        \App\Models\OrderStatusHistory::query()->whereKey($older->id)->update(['created_at' => now()->subDays(30)]);
        $this->storeCreateReturn($customer, $order, $itemId, 1)->assertUnprocessable();
    }

    private function createWithFiles(Customer $customer, Order $order, array $items): TestResponse
    {
        Auth::forgetGuards();
        $payload = ['order_id' => $order->id, 'reason' => 'مرفقات', 'items' => $items];

        return $this->withToken($this->customerToken($customer))->post('/api/v1/store/account/returns', $payload);
    }

    /**
     * task.txt (15): حدود المرفقات — 6 صور لبند واحد تُرفض (الحد 5)، و11 صورة
     * إجمالًا تُرفض (الحد 10)، وملف غير صورة/PDF يُرفض، و5MB للصورة الواحدة،
     * و25MB للإجمالي. الحدود المطبقة في الكود: 5 للبند، 10 للإجمالي.
     */
    public function test_store_create_rejects_attachment_limits(): void
    {
        $makeOrder = function (Customer $customer, int $items, float $price): array {
            $variants = [];
            for ($i = 0; $i < $items; $i++) {
                $variants[] = $this->purchasableVariant($price);
                $this->giveStock(end($variants), 5);
            }
            $city = City::factory()->create(['shipping_cost' => 0]);
            $token = $this->customerToken($customer);
            Auth::forgetGuards();
            foreach ($variants as $variant) {
                $this->withToken($token)->postJson('/api/v1/store/cart/items', [
                    'product_variant_id' => $variant->id, 'qty' => 1,
                ])->assertOk();
            }
            Auth::forgetGuards();
            $orderId = $this->withToken($token)->postJson('/api/v1/store/checkout', [
                'city_id' => $city->id, 'shipping_address_line' => 'عنوان', 'payment_method' => 'cod',
            ])->assertCreated()->json('data.id');

            return [Order::findOrFail($orderId), $token];
        };
        // ملفات بحجم دقيق: create() يولّد ملفًا بالكيلوبايت المطلوب ونوع image/jpeg.
        $images = fn (int $n, int $kb = 100): array => array_map(
            fn (int $i): UploadedFile => UploadedFile::fake()->create("p{$i}.jpg", $kb, 'image/jpeg'),
            range(1, $n)
        );

        // 6 صور لبند واحد (الحد 5).
        $customer = Customer::factory()->create();
        [$order,] = $makeOrder($customer, 1, 50);
        $this->createWithFiles($customer, $order, [[
            'order_item_id' => $order->items->first()->id, 'requested_qty' => 1,
            'attachments' => $images(6),
        ]])->assertUnprocessable()->assertJsonValidationErrors('items.0.attachments');

        // 11 صورة إجمالًا على بندين (الحد 10).
        $customer = Customer::factory()->create();
        [$order,] = $makeOrder($customer, 2, 50);
        $ids = $order->items->pluck('id')->all();
        $this->createWithFiles($customer, $order, [
            ['order_item_id' => $ids[0], 'requested_qty' => 1, 'attachments' => $images(5)],
            ['order_item_id' => $ids[1], 'requested_qty' => 1, 'attachments' => $images(6)],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.1.attachments');

        // PDF مرفوض (صور فقط).
        $customer = Customer::factory()->create();
        [$order,] = $makeOrder($customer, 1, 50);
        $this->createWithFiles($customer, $order, [[
            'order_item_id' => $order->items->first()->id, 'requested_qty' => 1,
            'attachments' => [UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')],
        ]])->assertUnprocessable()->assertJsonValidationErrors('items.0.attachments.0');

        // صورة أكبر من 5MB تُرفض.
        $customer = Customer::factory()->create();
        [$order,] = $makeOrder($customer, 1, 50);
        $this->createWithFiles($customer, $order, [[
            'order_item_id' => $order->items->first()->id, 'requested_qty' => 1,
            'attachments' => [UploadedFile::fake()->create('big.jpg', 5121, 'image/jpeg')],
        ]])->assertUnprocessable()->assertJsonValidationErrors('items.0.attachments.0');

        // إجمالي أكبر من 25MB يُرفض (6 ملفات × 5MB على بندين، وكل ملف ضمن حده).
        $customer = Customer::factory()->create();
        [$order,] = $makeOrder($customer, 2, 50);
        $ids = $order->items->pluck('id')->all();
        $big = fn (int $n): array => array_map(
            fn (int $i): UploadedFile => UploadedFile::fake()->create("b{$i}.jpg", 5000, 'image/jpeg'),
            range(1, $n)
        );
        $this->createWithFiles($customer, $order, [
            ['order_item_id' => $ids[0], 'requested_qty' => 1, 'attachments' => $big(3)],
            ['order_item_id' => $ids[1], 'requested_qty' => 1, 'attachments' => $big(3)],
        ])->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    private function branchStaff(Branch $branch, array $permissions): array
    {
        $staff = User::factory()->create(['branch_id' => $branch->id]);
        $staff->givePermissionTo($permissions);

        return [$staff, $staff->createToken('branch-token-'.uniqid())->plainTextToken];
    }

    private function branchAdminRequest(string $token, string $method, string $url, array $data = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->{$method}($url, $data);
    }

    /**
     * task.txt (9): عزل الفروع على إجراءات المرتجع — الموافقة والاستلام والرد
     * والحذف والعرض والفهرس، والموافقة بفرع مختلف، والطلب بلا فرع للمركزي فقط.
     */
    public function test_admin_return_actions_enforce_branch_isolation(): void
    {
        $branchA = $this->branch;
        $branchB = Branch::factory()->create();
        $perms = ['returns.view', 'returns.approve', 'returns.receive', 'returns.refund', 'returns.delete'];
        [, $tokenA] = $this->branchStaff($branchA, $perms);
        [, $tokenB] = $this->branchStaff($branchB, $perms);

        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 10);
        $customer = Customer::factory()->create();
        $order = $this->deliveredOrder($customer, $variant, 2);
        $order->update(['allocated_branch_id' => $branchA->id]);
        $created = $this->storeCreateReturn($customer, $order, $order->items->first()->id, 2)->assertCreated();
        $returnId = (int) $created->json('data.id');

        // الموافقة من فرع آخر بفرعه مرفوضة قبل أي منطق حالة.
        $this->branchAdminRequest($tokenB, 'postJson', "/api/v1/admin/returns/{$returnId}/approve", ['branch_id' => $branchB->id])
            ->assertForbidden();

        // الموافقة من الفرع الصحيح تنجح وتثبت الفرع.
        $this->branchAdminRequest($tokenA, 'postJson', "/api/v1/admin/returns/{$returnId}/approve", ['branch_id' => $branchA->id])
            ->assertOk()->assertJsonPath('data.branch_id', $branchA->id);
        $itemId = (int) ReturnRequest::findOrFail($returnId)->items()->firstOrFail()->id;

        // العرض والاستلام والرد والحذف من فرع آخر كلها 403.
        $this->branchAdminRequest($tokenB, 'getJson', "/api/v1/admin/returns/{$returnId}")->assertForbidden();
        $this->branchAdminRequest($tokenB, 'postJson', "/api/v1/admin/returns/{$returnId}/receive", [
            'items' => [['id' => $itemId, 'received_good_qty' => 2, 'received_damaged_qty' => 0]],
        ])->assertForbidden();
        $this->branchAdminRequest($tokenA, 'postJson', "/api/v1/admin/returns/{$returnId}/receive", [
            'items' => [['id' => $itemId, 'received_good_qty' => 2, 'received_damaged_qty' => 0]],
        ])->assertOk();
        $this->branchAdminRequest($tokenB, 'postJson', "/api/v1/admin/returns/{$returnId}/refund", ['refund_method' => 'cash'])
            ->assertForbidden();
        $this->branchAdminRequest($tokenB, 'deleteJson', "/api/v1/admin/returns/{$returnId}")
            ->assertForbidden();

        // الفهرس من فرع آخر لا يعرض المرتجع.
        $this->branchAdminRequest($tokenB, 'getJson', '/api/v1/admin/returns')
            ->assertOk()->assertJsonMissing(['id' => $returnId]);

        // طلب بلا فرع ومرتجع بلا فرع: موظف الفرع مرفوض.
        $ghostOrder = Order::factory()->create([
            'customer_id' => $customer->id, 'status' => \App\Enums\OrderStatus::Delivered,
            'channel' => 'store', 'payment_status' => 'paid', 'payment_method' => 'cod',
            'allocated_branch_id' => null,
        ]);
        $ghostItem = \App\Models\OrderItem::factory()->create(['order_id' => $ghostOrder->id, 'qty' => 1]);
        \App\Models\OrderStatusHistory::forceCreate([
            'order_id' => $ghostOrder->id, 'from_status' => \App\Enums\OrderStatus::Confirmed->value,
            'to_status' => \App\Enums\OrderStatus::Delivered->value, 'changed_by_id' => $this->staff->id,
        ]);
        $ghostReturn = $this->storeCreateReturn($customer, $ghostOrder, $ghostItem->id, 1)->assertCreated()->json('data.id');
        $this->branchAdminRequest($tokenB, 'getJson', "/api/v1/admin/returns/{$ghostReturn}")->assertForbidden();
    }

    /**
     * task.txt (10): عميل لا يرى مرفق بند مرتجع أجنبي ولا صفحة المرتجع نفسها.
     */
    public function test_customer_cannot_open_foreign_return_attachment(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 10);
        $owner = Customer::factory()->create();
        $stranger = Customer::factory()->create();
        $order = $this->deliveredOrder($owner, $variant, 1);
        $returnId = $this->storeCreateReturn($owner, $order, $order->items->first()->id, 1)->assertCreated()->json('data.id');
        $item = ReturnRequest::findOrFail($returnId)->items()->firstOrFail();
        $item->update(['attachments' => ['return_requests/pending/uuid-x/photo.jpg']]);
        Storage::disk('r2')->put('return_requests/pending/uuid-x/photo.jpg', 'contents');

        Auth::forgetGuards();
        $this->withToken($this->customerToken($stranger))
            ->getJson("/api/v1/store/account/return-items/{$item->id}/attachments/0")
            ->assertNotFound();
        Auth::forgetGuards();
        $this->withToken($this->customerToken($stranger))
            ->getJson("/api/v1/store/account/returns/{$returnId}")
            ->assertNotFound();
    }

    /**
     * task.txt (16): سلوكيات تنزيل المرفق — خارج المدى، مفقود، موقّع،
     * تحويل، انتهاء دقيقتين، و503 عند تعطل R2.
     */
    public function test_store_attachment_download_behaviors(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 10);
        $customer = Customer::factory()->create();
        $order = $this->deliveredOrder($customer, $variant, 1);
        $response = $this->createWithFiles($customer, $order, [[
            'order_item_id' => $order->items->first()->id, 'requested_qty' => 1,
            'attachments' => [UploadedFile::fake()->create('photo.jpg', 200, 'image/jpeg')],
        ]])->assertCreated();
        $itemId = (int) $response->json('data.items.0.id');
        $path = ReturnRequest::findOrFail($response->json('data.id'))->items()->firstOrFail()->attachments[0];
        $this->assertStringStartsWith('return_requests/pending/', $path);
        $this->assertTrue(Storage::disk('r2')->exists($path));

        $url = fn (int $i, bool $signed = false): string => "/api/v1/store/account/return-items/{$itemId}/attachments/{$i}".($signed ? '?signed=1' : '');
        $asCustomer = function (string $u) use ($customer) {
            Auth::forgetGuards();

            return $this->withToken($this->customerToken($customer))->get($u);
        };

        // خارج المدى.
        $asCustomer($url(9))->assertNotFound();

        // ملف مفقود من القرص: 404 بلا توقيع، وJSON مع التوقيع.
        Storage::disk('r2')->delete($path);
        $asCustomer($url(0))->assertNotFound();
        $asCustomer($url(0, true))->assertOk()->assertJsonStructure(['url']);

        // التوقيع يحمل Cache-Control خاصًا وينتهي خلال دقيقتين.
        $captured = null;
        Storage::disk('r2')->buildTemporaryUrlsUsing(function ($p, $expiration) use (&$captured) {
            $captured = $expiration;

            return 'https://storage.example.test/'.rawurlencode($p).'?signed=1';
        });
        Storage::disk('r2')->put($path, 'contents');
        $asCustomer($url(0, true))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonStructure(['url']);
        $this->assertNotNull($captured);
        $this->assertEqualsWithDelta(now()->addMinutes(2)->getTimestamp(), $captured->getTimestamp(), 30);

        // بلا توقيع: تحويل 302 إلى الرابط الموقّت.
        $asCustomer($url(0))->assertRedirect();

        // تعطل R2 أثناء التوقيع: 503 بلا تفاصيل داخلية.
        $disk = \Mockery::mock();
        $disk->shouldReceive('exists')->andReturnTrue();
        $disk->shouldReceive('temporaryUrl')->andThrow(new \RuntimeException('R2 is down'));
        Storage::shouldReceive('disk')->with('r2')->andReturn($disk);
        $failed = $asCustomer($url(0, true))->assertStatus(503);
        $this->assertStringNotContainsString('R2 is down', (string) $failed->getContent());
    }
}
