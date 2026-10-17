<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\InventoryIdempotencyKey;
use App\Models\PriceList;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\PriceListService;
use App\Services\PricingService;
use App\Services\StocktakeService;
use Carbon\Carbon;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\PricingPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * انحدار جولة المراجعة الخامسة: C-1 تصعيد قائمة الفرع لعامة، H-1 تجاوز
 * expected_updated_at، M-1 سقوط الجملة لأسعار retail، M-3 تكرار البنود،
 * M-4 تحديث جزئي لعناصر الجرد، M-5 سياسة لقطة الجرد، M-6 استيراد مجمّع،
 * M-7 ترقيم مؤشر، M-8 مطابقة الدفتر، M-9 رصيد المحذوف ناعمًا،
 * L-2 بصمة عدم التكرار والتنظيف، L-4 مخزون منخفض وهمي، L-5 CSV، L-7 إعادة المحاولة.
 */
class SecurityReviewRound5Test extends TestCase
{
    use LazilyRefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([SettingSeeder::class, InventoryPermissionSeeder::class, PricingPermissionSeeder::class]);
        $this->actor = User::factory()->create();
    }

    // ---------- C-1: branch_id = null لا يحوّل قائمة الفرع لعامة ----------

    public function test_restricted_staff_cannot_make_own_list_global_via_null_branch(): void
    {
        $branchA = Branch::factory()->create();
        Branch::factory()->create();
        $staff = $this->staffWithPermissions(['price_lists.view', 'price_lists.update'], $branchA);
        $token = $staff->createToken('test')->plainTextToken;
        $list = PriceList::create(['name' => 'A list', 'type' => 'retail', 'branch_id' => $branchA->id, 'priority' => 5]);

        $this->requestWithToken($token, 'PUT', "/api/v1/admin/price-lists/{$list->id}", [
            'branch_id' => null, 'priority' => 10000,
        ])->assertForbidden();

        $list->refresh();
        $this->assertSame($branchA->id, $list->branch_id);
        $this->assertSame(5, $list->priority);

        // التحكم الإيجابي: إبقاء فرعه صراحة مسموح
        $this->requestWithToken($token, 'PUT', "/api/v1/admin/price-lists/{$list->id}", [
            'branch_id' => $branchA->id, 'priority' => 7,
        ])->assertOk();
        $this->assertSame(7, $list->refresh()->priority);
    }

    // ---------- H-1: استبدال البنود وحده يحرّك updated_at ----------

    public function test_items_only_update_invalidates_expected_updated_at(): void
    {
        $staff = $this->hqStaff();
        $token = $staff->createToken('test')->plainTextToken;
        $from = Branch::factory()->create();
        $to = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $other = ProductVariant::factory()->create();
        $this->movement($variant, $from, 10);
        $this->movement($other, $from, 10);

        Carbon::setTestNow('2026-10-15 10:00:00');
        $created = $this->requestWithToken($token, 'POST', '/api/v1/admin/transfers', [
            'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 2]],
        ])->assertCreated();
        $transferId = $created->json('data.id');
        $seenUpdatedAt = $created->json('data.updated_at');

        // بعد خمس ثوانٍ: استبدال البنود فقط بنفس قيم الرأس حرفيًا
        Carbon::setTestNow('2026-10-15 10:00:05');
        $this->requestWithToken($token, 'PUT', "/api/v1/admin/transfers/{$transferId}", [
            'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $other->id, 'requested_qty' => 3]],
        ])->assertOk();
        $this->assertNotSame($seenUpdatedAt, StockTransfer::findOrFail($transferId)->updated_at->toIso8601String());

        // الاعتماد بالطابع القديم يُرفض: البنود المعتمدة لم يراجعها أحد بهذا الطابع
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transferId}/approve", [
            'expected_updated_at' => Carbon::parse($seenUpdatedAt)->format('Y-m-d H:i:s'),
        ])->assertStatus(409);

        Carbon::setTestNow();
    }

    // ---------- M-1: قناة الجملة ترجع لقائمة retail قبل السعر الأساسي ----------

    public function test_wholesale_customer_falls_back_to_retail_list_before_base_price(): void
    {
        $variant = ProductVariant::factory()->create(['retail_price' => 100]);
        $retailList = PriceList::create(['name' => 'Promo', 'type' => 'retail', 'is_active' => true]);
        $retailList->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 80]);

        $wholesale = Customer::create([
            'name' => 'WS', 'email' => 'ws-r5@example.com', 'phone' => '01055500001',
            'company_name' => 'Company', 'tax_number' => '123', 'commercial_register' => '456',
            'password' => Hash::make('secret'), 'type' => CustomerType::Wholesale, 'status' => CustomerStatus::Approved,
        ]);

        $resolved = app(PricingService::class)->resolve($variant, $wholesale);
        $this->assertSame(80.0, $resolved['unit_price']);
        $this->assertSame('retail', $resolved['source']);

        // عند وجود عنصر في قائمة الجملة نفسها يبقى هو السعر
        $wholesaleList = PriceList::create(['name' => 'WS', 'type' => 'wholesale', 'is_active' => true]);
        $wholesaleList->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 90]);
        $resolved = app(PricingService::class)->resolve($variant, $wholesale);
        $this->assertSame(90.0, $resolved['unit_price']);
        $this->assertSame('wholesale', $resolved['source']);
    }

    // ---------- M-3: بنود تحويل مكررة = 422 لا 500 ----------

    public function test_duplicate_transfer_items_are_a_validation_error_not_a_server_error(): void
    {
        $staff = $this->hqStaff();
        $token = $staff->createToken('test')->plainTextToken;
        $from = Branch::factory()->create();
        $to = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();

        $this->requestWithToken($token, 'POST', '/api/v1/admin/transfers', [
            'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'items' => [
                ['product_variant_id' => $variant->id, 'requested_qty' => 1],
                ['product_variant_id' => $variant->id, 'requested_qty' => 2],
            ],
        ])->assertStatus(422);

        $this->assertSame(0, StockTransfer::count());
    }

    // ---------- M-4: تحديث جزئي لعناصر الجرد بلا حذف الغائبين ----------

    public function test_partial_stocktake_items_update_keeps_absent_items(): void
    {
        $staff = $this->hqStaff();
        $token = $staff->createToken('test')->plainTextToken;
        $branch = Branch::factory()->create();
        $a = ProductVariant::factory()->create();
        $b = ProductVariant::factory()->create();
        $this->movement($a, $branch, 10);
        $this->movement($b, $branch, 20);

        $created = $this->requestWithToken($token, 'POST', '/api/v1/admin/stocktakes', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $a->id, 'counted_qty' => 9]],
        ])->assertCreated();
        $stocktakeId = $created->json('data.id');

        // دفعة جزئية بصنف آخر فقط: الصنف الأول يبقى بعدّاده
        $response = $this->requestWithToken($token, 'PATCH', "/api/v1/admin/stocktakes/{$stocktakeId}/items", [
            'items' => [['product_variant_id' => $b->id, 'counted_qty' => 18]],
        ])->assertOk();
        $this->assertCount(2, $response->json('data.items'));

        // وتحديث عدّ الأول وحده لا يمس الثاني
        $this->requestWithToken($token, 'PATCH', "/api/v1/admin/stocktakes/{$stocktakeId}/items", [
            'items' => [['product_variant_id' => $a->id, 'counted_qty' => 8]],
        ])->assertOk();
        $items = Stocktake::findOrFail($stocktakeId)->items->keyBy('product_variant_id');
        $this->assertSame('8.0000', $items[$a->id]->counted_qty);
        $this->assertSame('18.0000', $items[$b->id]->counted_qty);
    }

    // ---------- M-5: سياسة apply_difference (بضبط صريح) تحفظ الحركات البينية ----------

    public function test_stocktake_approval_applies_difference_despite_intervening_movements(): void
    {
        // الافتراضي reject (يمنع ازدواج الفرق مع جردين متداخلين)؛ هنا نتحقق من
        // وضع apply_difference الاختياري لحالة الجلسة الواحدة.
        config(['inventory.stocktake_stale_policy' => 'apply_difference']);
        $staff = $this->hqStaff();
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $branch, 10);

        $service = app(StocktakeService::class);
        $stocktake = $service->create(['branch_id' => $branch->id, 'items' => [
            ['product_variant_id' => $variant->id, 'counted_qty' => 12],
        ]], $staff->id);

        // بيع 3 بعد العد
        $this->movement($variant, $branch, -3, StockMovementType::AdjustmentOut);
        $this->assertSame('7.0000', StockLevel::where('product_variant_id', $variant->id)->firstOrFail()->on_hand);

        // الاعتماد ينجح: فرق +2 فوق الرصيد الحالي = 9 (المعدود 12 − البيع البيني 3)
        $service->approve($stocktake, $staff->id);
        $this->assertSame('9.0000', StockLevel::where('product_variant_id', $variant->id)->firstOrFail()->on_hand);
        $this->assertSame('2.0000', StockMovement::where('type', StockMovementType::StocktakeGain)->firstOrFail()->quantity);
    }

    // ---------- M-6/L-5: استيراد مجمّع، وSKU بلا حساسية لحالة الأحرف ----------

    public function test_import_upserts_in_bulk_and_matches_sku_case_insensitively(): void
    {
        $staff = $this->hqStaff();
        $list = PriceList::create(['name' => 'Import', 'type' => 'retail']);
        $v1 = ProductVariant::factory()->create(['sku' => 'BAG-001']);
        $v2 = ProductVariant::factory()->create(['sku' => 'BAG-002']);
        $service = app(PriceListService::class);

        $csv = "sku,price,min_qty\nbag-001,50,1\nBAG-002,60,1\n";
        $report = $service->importCsv($list, UploadedFile::fake()->createWithContent('import.csv', $csv), $staff);
        $this->assertSame(2, $report['created']);
        $this->assertSame(0, $report['skipped']);
        $this->assertSame(2, $list->items()->count());

        $csv2 = "sku,price,min_qty\nBAG-001,55,1\n";
        $report = $service->importCsv($list, UploadedFile::fake()->createWithContent('import.csv', $csv2), $staff);
        $this->assertSame(0, $report['created']);
        $this->assertSame(1, $report['updated']);
        $this->assertSame('55.0000', $list->items()->where('product_variant_id', $v1->id)->firstOrFail()->price);
        $this->assertSame(2, $list->items()->count());
    }

    // ---------- M-7: ترقيم الحركات بالمؤشر ----------

    public function test_movements_listing_uses_cursor_pagination(): void
    {
        $staff = $this->hqStaff();
        $token = $staff->createToken('test')->plainTextToken;
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $branch, 1);
        $this->movement($variant, $branch, 1);
        $this->movement($variant, $branch, 1);

        $page1 = $this->requestWithToken($token, 'GET', '/api/v1/admin/inventory/movements?per_page=2')->assertOk();
        $this->assertCount(2, $page1->json('data'));
        $this->assertArrayNotHasKey('total', $page1->json('meta'));
        $cursor = $page1->json('meta.next_cursor');
        $this->assertNotEmpty($cursor);

        $page2 = $this->requestWithToken($token, 'GET', '/api/v1/admin/inventory/movements?per_page=2&cursor='.urlencode($cursor))->assertOk();
        $this->assertCount(1, $page2->json('data'));
        $this->assertNotSame($page1->json('data.0.id'), $page2->json('data.0.id'));
    }

    // ---------- M-8: أمر المطابقة يكشف الكتابة المباشرة ----------

    public function test_reconcile_command_detects_direct_balance_writes(): void
    {
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $branch, 7);

        $this->artisan('inventory:reconcile')->assertExitCode(0);

        DB::table('stock_levels')->where('product_variant_id', $variant->id)->update(['on_hand' => 99]);

        $this->artisan('inventory:reconcile')->assertExitCode(1);
    }

    // ---------- M-9: حذف متغير/منتج له رصيد ممنوع، والمحذوف قسرًا يُصفّى ولا يُزاد ----------

    public function test_variant_with_stock_cannot_be_deleted_and_trashed_variant_can_be_drawn_down(): void
    {
        $staff = $this->hqStaff();
        $token = $staff->createToken('test')->plainTextToken;
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $branch, 5);

        $this->requestWithToken($token, 'DELETE', "/api/v1/admin/products/{$variant->product_id}/variants/{$variant->id}")
            ->assertStatus(422);
        $this->requestWithToken($token, 'DELETE', "/api/v1/admin/products/{$variant->product_id}")
            ->assertStatus(422);
        $this->assertNotNull(ProductVariant::find($variant->id));

        // حذف قسري قديم (قبل الحارس): الرصيد لا يُحبس — إخراج مسموح وإدخال ممنوع
        $variant->delete();
        $this->assertNull(ProductVariant::find($variant->id));

        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'decrease', 'quantity' => 3]],
        ])->assertCreated();
        $this->assertSame('2.0000', StockLevel::where('product_variant_id', $variant->id)->firstOrFail()->on_hand);

        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'increase', 'quantity' => 1]],
        ])->assertStatus(422);
    }

    // ---------- L-2: البصمة تطابق "5" و5، والتنظيف يحذف القديم ----------

    public function test_idempotency_hash_normalizes_number_forms_and_prune_removes_old_keys(): void
    {
        $staff = $this->hqStaff();
        $token = $staff->createToken('test')->plainTextToken;
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();

        $headers = ['Idempotency-Key' => 'norm-key-1'];
        Auth::forgetGuards();
        $first = $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'increase', 'quantity' => 7]],
        ])->assertCreated();
        $movementId = $first->json('data.0.id');

        Auth::forgetGuards();
        $replay = $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'increase', 'quantity' => '7']],
        ])->assertCreated();
        $this->assertSame($movementId, $replay->json('data.0.id'));
        $this->assertSame('7.0000', StockLevel::where('product_variant_id', $variant->id)->firstOrFail()->on_hand);

        InventoryIdempotencyKey::query()->where('key', 'norm-key-1')->update(['created_at' => now()->subDays(8)]);
        InventoryIdempotencyKey::query()->create([
            'user_id' => $staff->id, 'key' => 'fresh-key', 'request_hash' => 'x',
        ]);
        $this->artisan('inventory:prune-idempotency-keys')->assertExitCode(0);
        $this->assertSame(0, InventoryIdempotencyKey::where('key', 'norm-key-1')->count());
        $this->assertSame(1, InventoryIdempotencyKey::where('key', 'fresh-key')->count());
    }

    // ---------- L-4: صف بلا حد إعادة طلب ليس مخزونًا منخفضًا ----------

    public function test_zero_rows_without_reorder_level_are_not_low_stock(): void
    {
        $staff = $this->hqStaff();
        $token = $staff->createToken('test')->plainTextToken;
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create(['reorder_level' => 0]);

        // صف رصيد صفري كأثر جانبي لأقفال lockLevels (صف وجهة تحويل مثلًا)
        DB::table('stock_levels')->insert([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id, 'location_id' => null,
            'on_hand' => 0, 'reserved' => 0, 'damaged' => 0, 'in_transit' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->requestWithToken($token, 'GET', '/api/v1/admin/inventory')->assertOk();
        $row = collect($response->json('data'))->firstWhere('product_variant_id', $variant->id);
        $this->assertNotNull($row);
        $this->assertFalse($row['is_low_stock']);

        $low = $this->requestWithToken($token, 'GET', '/api/v1/admin/inventory?low_stock=1')->assertOk();
        $this->assertCount(0, $low->json('data'));
    }

    // ---------- L-7: إعادة محاولة إنشاء تحويل/جرد بنفس المفتاح لا تكرر ----------

    public function test_store_endpoints_honor_idempotency_key(): void
    {
        $staff = $this->hqStaff();
        $token = $staff->createToken('test')->plainTextToken;
        $from = Branch::factory()->create();
        $to = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $payload = [
            'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 2]],
        ];

        Auth::forgetGuards();
        $first = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'trf-key-1'])
            ->postJson('/api/v1/admin/transfers', $payload)->assertCreated();
        Auth::forgetGuards();
        $replay = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'trf-key-1'])
            ->postJson('/api/v1/admin/transfers', $payload)->assertCreated();
        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame(1, StockTransfer::count());

        Auth::forgetGuards();
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'trf-key-1'])
            ->postJson('/api/v1/admin/transfers', [...$payload, 'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 9]]])
            ->assertStatus(409);

        $stocktakePayload = ['branch_id' => $from->id, 'items' => [['product_variant_id' => $variant->id, 'counted_qty' => 1]]];
        Auth::forgetGuards();
        $first = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'stk-key-1'])
            ->postJson('/api/v1/admin/stocktakes', $stocktakePayload)->assertCreated();
        Auth::forgetGuards();
        $replay = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'stk-key-1'])
            ->postJson('/api/v1/admin/stocktakes', $stocktakePayload)->assertCreated();
        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame(1, Stocktake::count());
    }

    // ---------- helpers ----------

    private function hqStaff(): User
    {
        return $this->staffWithPermissions([
            'inventory.view', 'inventory.adjust', 'inventory.all_branches',
            'transfers.view', 'transfers.create', 'transfers.update', 'transfers.delete',
            'transfers.approve', 'transfers.ship', 'transfers.receive',
            'stocktakes.view', 'stocktakes.create', 'stocktakes.update', 'stocktakes.delete', 'stocktakes.approve',
            'price_lists.view', 'price_lists.create', 'price_lists.update', 'price_lists.delete',
            'price_lists.import', 'price_lists.export',
            'products.view', 'products.update', 'products.delete',
        ]);
    }

    private function staffWithPermissions(array $permissions, ?Branch $branch = null): User
    {
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'staff');
        }

        $staff = User::factory()->create(['branch_id' => $branch?->id]);
        $staff->givePermissionTo($permissions);

        return $staff;
    }

    private function requestWithToken(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->json($method, $uri, $data);
    }

    private function movement(ProductVariant $variant, Branch $branch, float $qty, StockMovementType $type = StockMovementType::AdjustmentIn): void
    {
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id,
            'type' => $type, 'affected_bucket' => StockBucket::OnHand,
            'quantity' => $qty, 'created_by' => $this->actor->id,
        ]);
    }
}
