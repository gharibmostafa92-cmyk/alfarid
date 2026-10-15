<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\PriceList;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\StocktakeService;
use App\Services\TransferService;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\PricingPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * انحدار جولة المراجعة الرابعة: M4 نطاق قوائم الأسعار، M5 مخزون المعطّلات،
 * M6 حد إعادة الطلب، M7 تكلفة الحركات، M8 التواريخ، M9 العملة،
 * M10 ترقيم الحركات، M12 قيود قاعدة البيانات، L2/L5.
 */
class SecurityReviewRound4Test extends TestCase
{
    use LazilyRefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([SettingSeeder::class, InventoryPermissionSeeder::class, PricingPermissionSeeder::class]);
        $this->actor = User::factory()->create();
    }

    // ---------- M4: قوائم الأسعار مقيدة بنطاق الفرع ----------

    public function test_branch_staff_only_sees_and_touches_own_branch_price_lists(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $staff = $this->staffWithPermissions([
            'price_lists.view', 'price_lists.create', 'price_lists.update', 'price_lists.delete',
            'price_lists.import', 'price_lists.export',
        ], $branchA);
        $token = $staff->createToken('test')->plainTextToken;

        $global = PriceList::create(['name' => 'Global', 'type' => 'retail']);
        $listA = PriceList::create(['name' => 'A', 'type' => 'retail', 'branch_id' => $branchA->id]);
        $listB = PriceList::create(['name' => 'B', 'type' => 'retail', 'branch_id' => $branchB->id]);

        $ids = collect($this->requestWithToken($token, 'GET', '/api/v1/admin/price-lists')->assertOk()->json('data'))->pluck('id');
        $this->assertSame([$listA->id], $ids->all()); // لا العامة ولا فرع آخر

        $this->requestWithToken($token, 'GET', "/api/v1/admin/price-lists/{$listB->id}")->assertNotFound();
        $this->requestWithToken($token, 'PUT', "/api/v1/admin/price-lists/{$global->id}", ['name' => 'Hijack'])->assertForbidden();

        $this->requestWithToken($token, 'POST', '/api/v1/admin/price-lists', [
            'name' => 'For B', 'type' => 'retail', 'branch_id' => $branchB->id,
        ])->assertForbidden();

        $this->requestWithToken($token, 'POST', '/api/v1/admin/price-lists', [
            'name' => 'Mine', 'type' => 'retail',
        ])->assertCreated();
        // تُربط بفرعه تلقائيًا
        $this->assertSame($branchA->id, PriceList::where('name', 'Mine')->firstOrFail()->branch_id);
    }

    // ---------- M5: رصيد المعطّلات قابل للتصفية لا محبوس ----------

    public function test_inactive_variant_stock_can_be_drawn_down_but_not_increased(): void
    {
        $staff = $this->hqStaff();
        $token = $staff->createToken('test')->plainTextToken;
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $branch, 10);
        $variant->update(['is_active' => false]);

        // إخراج من المعطّل: مسموح (تصفية الرصيد)
        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'decrease', 'quantity' => 4]],
        ])->assertCreated();
        $this->assertSame('6.0000', StockLevel::where('product_variant_id', $variant->id)->firstOrFail()->on_hand);

        // إدخال على المعطّل: ممنوع
        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'increase', 'quantity' => 1]],
        ])->assertUnprocessable();

        // والجرد والتحويل الصادر يعملان على المعطّل أيضًا
        $stocktake = app(StocktakeService::class)->create(['branch_id' => $branch->id, 'items' => [
            ['product_variant_id' => $variant->id, 'counted_qty' => 6],
        ]], $staff->id);
        $this->assertSame('6.0000', $stocktake->items->first()->system_qty);

        $transfer = app(TransferService::class)->create([
            'from_branch_id' => $branch->id, 'to_branch_id' => Branch::factory()->create()->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 2]],
        ], $staff->id);
        $this->assertSame('draft', $transfer->status->value);
    }

    // ---------- M6: حد إعادة الطلب الفعّال ----------

    public function test_low_stock_uses_variant_reorder_level_when_level_has_none(): void
    {
        $staff = $this->hqStaff();
        $token = $staff->createToken('test')->plainTextToken;
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create(['reorder_level' => 5]);
        $this->movement($variant, $branch, 3);

        $data = $this->requestWithToken($token, 'GET', '/api/v1/admin/inventory?low_stock=1')->assertOk()->json('data');
        $this->assertCount(1, $data);
        $this->assertSame(5.0, (float) $data[0]['reorder_level']);
        $this->assertTrue($data[0]['is_low_stock']);

        $this->movement($variant, $branch, 10);
        $data = $this->requestWithToken($token, 'GET', '/api/v1/admin/inventory?low_stock=1')->assertOk()->json('data');
        $this->assertCount(0, $data);
    }

    // ---------- M7: unit_cost خلف صلاحية ----------

    public function test_movement_unit_cost_requires_view_cost_permission(): void
    {
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create(['cost_price' => 42]);
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id,
            'type' => StockMovementType::AdjustmentIn, 'affected_bucket' => StockBucket::OnHand,
            'quantity' => 5, 'unit_cost' => 42, 'created_by' => $this->actor->id,
        ]);

        $plain = $this->staffWithPermissions(['inventory.view', 'inventory.all_branches']);
        $plainToken = $plain->createToken('test')->plainTextToken;
        $row = $this->requestWithToken($plainToken, 'GET', '/api/v1/admin/inventory/movements')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('unit_cost', $row);

        $privileged = $this->staffWithPermissions(['inventory.view', 'inventory.all_branches', 'inventory.view_cost']);
        $token = $privileged->createToken('test')->plainTextToken;
        $row = $this->requestWithToken($token, 'GET', '/api/v1/admin/inventory/movements')->assertOk()->json('data.0');
        $this->assertSame('42.0000', $row['unit_cost']);
    }

    // ---------- M8 + M9: تواريخ صارمة وعملة واحدة ----------

    public function test_price_list_dates_are_interpreted_in_business_timezone_and_stored_utc(): void
    {
        $staff = $this->hqStaff();
        $token = $staff->createToken('test')->plainTextToken;

        // يناير: القاهرة UTC+2 — منتصف ليل القاهرة = 22:00 UTC في اليوم السابق
        $created = $this->requestWithToken($token, 'POST', '/api/v1/admin/price-lists', [
            'name' => 'TZ', 'type' => 'retail', 'starts_at' => '2027-01-15 00:00:00',
        ])->assertCreated()->json('data');
        $this->assertStringStartsWith('2027-01-14T22:00:00', $created['starts_at']);

        $this->requestWithToken($token, 'POST', '/api/v1/admin/price-lists', [
            'name' => 'USD list', 'type' => 'retail', 'currency' => 'USD',
        ])->assertUnprocessable(); // M9: EGP فقط حاليًا
    }

    public function test_csv_import_rejects_ambiguous_dates_and_accepts_iso_with_offset(): void
    {
        $staff = $this->hqStaff();
        $token = $staff->createToken('test')->plainTextToken;
        $list = PriceList::create(['name' => 'R', 'type' => 'retail']);
        $variant = ProductVariant::factory()->create();

        $csv = "variant_id,price,starts_at\n"
            ."{$variant->id},10,01/02/2027\n"
            ."{$variant->id},10,next monday\n"
            ."{$variant->id},10,2027-01-15T00:00:00+02:00\n";
        $file = UploadedFile::fake()->createWithContent('import.csv', $csv);

        Auth::forgetGuards();
        $report = $this->withToken($token)->postJson("/api/v1/admin/price-lists/{$list->id}/import", ['file' => $file])
            ->assertOk()->json('data');
        $this->assertSame(1, $report['created']);
        $this->assertSame(2, $report['skipped']);
        $this->assertSame('2027-01-14 22:00:00', $list->items()->firstOrFail()->starts_at->format('Y-m-d H:i:s'));
    }

    // ---------- M10: الحركات بلا total ----------

    public function test_movements_listing_uses_simple_pagination_without_total(): void
    {
        $staff = $this->hqStaff();
        $token = $staff->createToken('test')->plainTextToken;
        $this->movement(ProductVariant::factory()->create(), Branch::factory()->create(), 1);

        $response = $this->requestWithToken($token, 'GET', '/api/v1/admin/inventory/movements')->assertOk();
        $this->assertArrayNotHasKey('total', $response->json('meta'));
        $this->assertCount(1, $response->json('data'));
    }

    // ---------- M12: الثوابت في قاعدة البيانات ----------

    public function test_database_rejects_impossible_transfer_and_stocktake_quantities(): void
    {
        $from = Branch::factory()->create();
        $to = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $transferId = DB::table('stock_transfers')->insertGetId([
            'transfer_number' => 'TRF-T1', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'status' => 'draft', 'created_by' => $this->actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('stock_transfer_items')->insert([
            'stock_transfer_id' => $transferId, 'product_variant_id' => $variant->id, 'requested_qty' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // سطر مكرر لنفس الصنف والموقعين
        $this->assertThrowsQuery(fn () => DB::table('stock_transfer_items')->insert([
            'stock_transfer_id' => $transferId, 'product_variant_id' => $variant->id, 'requested_qty' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]));
        // معتمد أكبر من المطلوب
        $this->assertThrowsQuery(fn () => DB::table('stock_transfer_items')->insert([
            'stock_transfer_id' => $transferId, 'product_variant_id' => ProductVariant::factory()->create()->id,
            'requested_qty' => 3, 'approved_qty' => 9, 'created_at' => now(), 'updated_at' => now(),
        ]));

        $stocktakeId = DB::table('stocktakes')->insertGetId([
            'stocktake_number' => 'STK-T1', 'branch_id' => $from->id, 'status' => 'draft',
            'created_by' => $this->actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('stocktake_items')->insert([
            'stocktake_id' => $stocktakeId, 'product_variant_id' => $variant->id, 'system_qty' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // عدّ سالب + صنف مكرر في نفس الجلسة
        $this->assertThrowsQuery(fn () => DB::table('stocktake_items')->insert([
            'stocktake_id' => $stocktakeId, 'product_variant_id' => ProductVariant::factory()->create()->id,
            'system_qty' => 1, 'counted_qty' => -2, 'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->assertThrowsQuery(fn () => DB::table('stocktake_items')->insert([
            'stocktake_id' => $stocktakeId, 'product_variant_id' => $variant->id,
            'system_qty' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    // ---------- L2 + L5 ----------

    public function test_record_movement_without_creator_fails_loudly_and_reference_uses_morph_alias(): void
    {
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();

        try {
            app(InventoryService::class)->recordMovement([
                'product_variant_id' => $variant->id, 'branch_id' => $branch->id,
                'type' => StockMovementType::AdjustmentIn, 'affected_bucket' => StockBucket::OnHand, 'quantity' => 1,
            ]);
            $this->fail('missing created_by must raise LogicException');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('created_by', $e->getMessage());
        }

        $staff = $this->hqStaff();
        $to = Branch::factory()->create();
        $this->movement($variant, $branch, 5);
        $transfer = app(TransferService::class)->create([
            'from_branch_id' => $branch->id, 'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 2]],
        ], $staff->id);
        app(TransferService::class)->approve($transfer, [], $staff->id);
        $this->assertSame('stock_transfer', StockMovement::where('type', StockMovementType::Reserve)->firstOrFail()->reference_type);
    }

    // ---------- helpers ----------

    private function assertThrowsQuery(callable $fn): void
    {
        try {
            DB::transaction($fn); // savepoint: فشل القيد لا يسمّم معاملة الاختبار الخارجية
            $this->fail('expected a database constraint violation');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function hqStaff(): User
    {
        return $this->staffWithPermissions([
            'inventory.view', 'inventory.adjust', 'inventory.all_branches', 'inventory.view_cost',
            'transfers.view', 'transfers.create', 'transfers.update', 'transfers.delete',
            'transfers.approve', 'transfers.ship', 'transfers.receive',
            'stocktakes.view', 'stocktakes.create', 'stocktakes.update', 'stocktakes.delete', 'stocktakes.approve',
            'price_lists.view', 'price_lists.create', 'price_lists.update', 'price_lists.delete',
            'price_lists.import', 'price_lists.export',
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

    private function movement(ProductVariant $variant, Branch $branch, float $qty): void
    {
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id,
            'type' => StockMovementType::AdjustmentIn, 'affected_bucket' => StockBucket::OnHand,
            'quantity' => $qty, 'created_by' => $this->actor->id,
        ]);
    }
}
