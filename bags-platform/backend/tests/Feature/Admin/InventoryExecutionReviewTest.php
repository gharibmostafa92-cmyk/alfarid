<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Api\V1\Pos\PosStockController;
use App\Models\Branch;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\IdempotencyFingerprint;
use App\Services\InventoryService;
use App\Services\ProductionReceiptService;
use App\Services\StocktakeService;
use App\Services\TransferService;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * انحدار الإصلاحات H1–H4 وM1–M7 وL1–L6 من مراجعة تنفيذ المخزون.
 */
class InventoryExecutionReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([SettingSeeder::class, InventoryPermissionSeeder::class]);
        $this->staff = User::factory()->create();
        $this->staff->givePermissionTo(Permission::where('guard_name', 'staff')->get());
    }

    // ---------- H3: الاستلام/المرتجع لصنف معطّل ينجح، والإدخال الجديد يُرفض ----------

    public function test_transfer_receive_to_inactive_variant_succeeds(): void
    {
        $from = Branch::factory()->create();
        $to = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $from, 5);

        $transfer = app(TransferService::class)->create([
            'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 2]],
        ], $this->staff->id);
        app(TransferService::class)->approve($transfer, [], $this->staff->id);
        app(TransferService::class)->ship($transfer, [], $this->staff->id);

        // تعطيل الصنف وهو في الطريق — كان يعلق التحويل في Shipped للأبد.
        $variant->update(['is_active' => false]);

        app(TransferService::class)->receive($transfer, [], $this->staff->id);

        $this->assertSame('received', $transfer->fresh()->status->value);
        $this->assertSame('2.0000', StockLevel::where('product_variant_id', $variant->id)
            ->where('branch_id', $to->id)->firstOrFail()->on_hand);
    }

    public function test_new_inbound_to_inactive_variant_is_still_rejected_while_returns_pass(): void
    {
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create(['is_active' => false]);

        try {
            $this->movement($variant, $branch, 3, 'adjustment_in');
            $this->fail('الإدخال الجديد على متغير معطّل يجب أن يُرفض.');
        } catch (BusinessException) {
            // متوقع.
        }

        // مرتجع بيع لصنف أُوقف بيعه — بضاعة موجودة فعلًا فيجب تسجيلها.
        $this->movement($variant, $branch, 3, 'sale_return');
        $this->assertSame('3.0000', StockLevel::firstOrFail()->on_hand);
    }

    // ---------- H4: طلب التحويل من فرع الوجهة ----------

    public function test_destination_branch_staff_can_request_transfer(): void
    {
        $from = Branch::factory()->create();
        $to = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $requester = $this->branchStaff($to, ['transfers.create']);

        $transfer = app(TransferService::class)->create([
            'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 1]],
        ], $requester->id);

        $this->assertSame($from->id, $transfer->from_branch_id);
        $this->assertSame($to->id, $transfer->to_branch_id);
    }

    public function test_third_branch_staff_cannot_create_transfer(): void
    {
        $outsider = $this->branchStaff(Branch::factory()->create(), ['transfers.create']);

        $this->expectException(AuthorizationException::class);
        app(TransferService::class)->create([
            'from_branch_id' => Branch::factory()->create()->id,
            'to_branch_id' => Branch::factory()->create()->id,
            'items' => [['product_variant_id' => ProductVariant::factory()->create()->id, 'requested_qty' => 1]],
        ], $outsider->id);
    }

    // ---------- M2: مفتاح عدم التكرار إلزامي ----------

    public function test_adjustments_without_idempotency_key_are_rejected(): void
    {
        Auth::forgetGuards();
        $branch = Branch::factory()->create();
        $payload = ['branch_id' => $branch->id, 'items' => [[
            'product_variant_id' => ProductVariant::factory()->create()->id,
            'adjustment_type' => 'increase', 'quantity' => 1,
        ]]];

        $this->withToken($this->staff->createToken('t')->plainTextToken)
            ->postJson('/api/v1/admin/inventory/adjustments', $payload)
            ->assertStatus(422);
        $this->assertDatabaseCount('stock_movements', 0);

        $this->api('POST', 'inventory/adjustments', $payload)->assertCreated();
        $this->assertDatabaseCount('stock_movements', 1);
    }

    // ---------- M3: سقف بنود الجرد ----------

    public function test_stocktake_session_has_total_item_cap(): void
    {
        config(['inventory.stocktake_max_items' => 2]);
        $branch = Branch::factory()->create();
        $variants = ProductVariant::factory()->count(3)->create();
        $stocktake = app(StocktakeService::class)->create(['branch_id' => $branch->id, 'items' => [
            ['product_variant_id' => $variants[0]->id],
            ['product_variant_id' => $variants[1]->id],
        ]], $this->staff->id);

        $this->expectException(BusinessException::class);
        app(StocktakeService::class)->upsertSomeItems($stocktake, [
            ['product_variant_id' => $variants[2]->id, 'counted_qty' => 1],
        ], $this->staff->id);
    }

    // ---------- M5: جرد بلا موقع في فرع يستخدم المواقع ----------

    public function test_branchless_stocktake_rejected_when_branch_uses_locations(): void
    {
        $branch = Branch::factory()->create();
        $location = Location::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $variant = ProductVariant::factory()->create();
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id, 'location_id' => $location->id,
            'type' => 'adjustment_in', 'affected_bucket' => 'on_hand', 'quantity' => 4,
            'created_by' => $this->staff->id,
        ]);

        try {
            app(StocktakeService::class)->create(['branch_id' => $branch->id, 'items' => [
                ['product_variant_id' => $variant->id, 'counted_qty' => 4],
            ]], $this->staff->id);
            $this->fail('الجرد الإجمالي لفرع يستخدم المواقع يجب أن يُرفض.');
        } catch (BusinessException) {
            // متوقع.
        }

        $scoped = app(StocktakeService::class)->create([
            'branch_id' => $branch->id, 'location_id' => $location->id,
            'items' => [['product_variant_id' => $variant->id, 'counted_qty' => 4]],
        ], $this->staff->id);
        $this->assertSame($location->id, $scoped->location_id);
    }

    // ---------- M6: الجرد الأعمى ----------

    public function test_counter_cannot_see_system_quantities(): void
    {
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $branch, 10);
        $stocktake = app(StocktakeService::class)->create(['branch_id' => $branch->id, 'items' => [
            ['product_variant_id' => $variant->id, 'counted_qty' => 9],
        ]], $this->staff->id);

        $counter = $this->branchStaff($branch, ['stocktakes.view']);
        $items = $this->api('GET', "stocktakes/{$stocktake->id}", [], $counter)->assertOk()->json('data.items');
        $this->assertArrayNotHasKey('system_qty', $items[0]);
        $this->assertArrayNotHasKey('difference', $items[0]);
        $this->assertSame(9, (int) $items[0]['counted_qty']);

        // المعتمِد يرى كل شيء.
        $items = $this->api('GET', "stocktakes/{$stocktake->id}")->assertOk()->json('data.items');
        $this->assertSame(10, (int) $items[0]['system_qty']);
    }

    // ---------- M4: نطاق المواقع ----------

    public function test_branch_staff_cannot_read_other_branch_locations(): void
    {
        $own = Branch::factory()->create();
        $other = Branch::factory()->create();
        Location::factory()->create(['branch_id' => $other->id]);
        $staff = $this->branchStaff($own, ['locations.view']);

        $this->api('GET', "branches/{$other->id}/locations", [], $staff)->assertForbidden();
    }

    // ---------- L2: بصمة التكلفة بأربع خانات ----------

    public function test_fingerprint_distinguishes_unit_cost_at_four_decimals(): void
    {
        $base = ['unit_cost' => 1.23, 'quantity' => 2];
        $this->assertSame(
            IdempotencyFingerprint::hash($base),
            IdempotencyFingerprint::hash(['unit_cost' => 1.2300, 'quantity' => 2])
        );
        $this->assertNotSame(
            IdempotencyFingerprint::hash($base),
            IdempotencyFingerprint::hash(['unit_cost' => 1.2345, 'quantity' => 2])
        );
    }

    // ---------- L3: حذف الموقع ينظّف الصفرية ----------

    public function test_deleting_location_cleans_zero_levels_but_keeps_ledger_block(): void
    {
        // صلاحيات المواقع غير مزروعة — تُنشأ هنا كما في BranchEndpointsTest.
        foreach (['locations.view', 'locations.create', 'locations.update', 'locations.delete', 'locations.manage'] as $name) {
            $this->staff->givePermissionTo(Permission::findOrCreate($name, 'staff'));
        }
        $branch = Branch::factory()->create();
        $empty = Location::factory()->create(['branch_id' => $branch->id]);
        StockLevel::forceCreate([
            'product_variant_id' => ProductVariant::factory()->create()->id,
            'branch_id' => $branch->id, 'location_id' => $empty->id,
        ]);

        $this->api('DELETE', "branches/{$branch->id}/locations/{$empty->id}")->assertNoContent();
        $this->assertDatabaseMissing('locations', ['id' => $empty->id]);
        $this->assertDatabaseMissing('stock_levels', ['location_id' => $empty->id]);

        $used = Location::factory()->create(['branch_id' => $branch->id]);
        $variant = ProductVariant::factory()->create();
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id, 'location_id' => $used->id,
            'type' => 'adjustment_in', 'affected_bucket' => 'on_hand', 'quantity' => 1,
            'created_by' => $this->staff->id,
        ]);
        $this->api('DELETE', "branches/{$branch->id}/locations/{$used->id}")->assertStatus(409);
        $this->assertDatabaseHas('locations', ['id' => $used->id]);
    }

    // ---------- helpers ----------

    public function test_initial_stocktake_items_cannot_bypass_session_cap(): void
    {
        config(['inventory.stocktake_max_items' => 1]);
        $branch = Branch::factory()->create();
        $variants = ProductVariant::factory()->count(2)->create();

        try {
            app(StocktakeService::class)->create([
                'branch_id' => $branch->id,
                'items' => $variants->map(fn ($variant) => ['product_variant_id' => $variant->id])->all(),
            ], $this->staff->id);
            $this->fail('Initial creation must enforce the session cap.');
        } catch (BusinessException) {
            $this->assertDatabaseCount('stocktakes', 0);
            $this->assertDatabaseCount('stocktake_items', 0);
        }
    }

    public function test_stocktake_scope_is_checked_again_before_approval(): void
    {
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $count = app(StocktakeService::class)->create([
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'counted_qty' => 4]],
        ], $this->staff->id);
        $location = Location::factory()->create(['branch_id' => $branch->id]);
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id, 'location_id' => $location->id,
            'type' => 'adjustment_in', 'affected_bucket' => 'on_hand', 'quantity' => 4,
            'created_by' => $this->staff->id,
        ]);

        $this->api('POST', "stocktakes/{$count->id}/approve")->assertUnprocessable();
        $this->assertSame('draft', $count->fresh()->status->value);
        $this->assertDatabaseCount('stock_movements', 1);

        // A session that can no longer be approved must still be cancellable.
        app(StocktakeService::class)->update($count, ['status' => 'cancelled'], $this->staff->id);
        $this->assertSame('cancelled', $count->fresh()->status->value);
    }

    public function test_empty_located_balances_do_not_prevent_unlocated_counts(): void
    {
        $branch = Branch::factory()->create();
        $location = Location::factory()->create(['branch_id' => $branch->id]);
        $variant = ProductVariant::factory()->create();
        StockLevel::forceCreate(['product_variant_id' => $variant->id, 'branch_id' => $branch->id, 'location_id' => $location->id]);

        $count = app(StocktakeService::class)->create(['branch_id' => $branch->id], $this->staff->id);
        $this->assertNull($count->location_id);
    }

    public function test_stock_locks_restore_the_callers_timeout_even_for_empty_keys(): void
    {
        $variant = ProductVariant::factory()->create();
        $branch = Branch::factory()->create();
        DB::statement("SET LOCAL lock_timeout = '1700ms'");
        foreach ([[], [['product_variant_id' => $variant->id, 'branch_id' => $branch->id]]] as $keys) {
            app(InventoryService::class)->lockLevels($keys);
            $value = DB::selectOne("SELECT current_setting('lock_timeout') AS value")->value;
            $this->assertSame('1700ms', $value);
        }
    }

    public function test_inactive_destination_is_rejected_before_approval_and_shipment(): void
    {
        $from = Branch::factory()->create();
        $to = Branch::factory()->create();
        $location = Location::factory()->create(['branch_id' => $to->id]);
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $from, 5);
        $transfer = app(TransferService::class)->create([
            'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'to_location_id' => $location->id, 'requested_qty' => 2]],
        ], $this->staff->id);
        $location->update(['is_active' => false]);
        $this->api('POST', "transfers/{$transfer->id}/approve")->assertUnprocessable();
        $location->update(['is_active' => true]);
        $this->api('POST', "transfers/{$transfer->id}/approve")->assertOk();
        $location->update(['is_active' => false]);
        $this->api('POST', "transfers/{$transfer->id}/ship")->assertUnprocessable();
        $this->assertSame('approved', $transfer->fresh()->status->value);
        $this->assertSame('5.0000', StockLevel::where('branch_id', $from->id)->firstOrFail()->on_hand);
    }

    public function test_disabled_variant_cannot_ship_but_an_inflight_transfer_can_receive(): void
    {
        $from = Branch::factory()->create();
        $to = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $from, 5);
        $transfer = app(TransferService::class)->create([
            'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 2]],
        ], $this->staff->id);
        app(TransferService::class)->approve($transfer, [], $this->staff->id);
        $variant->update(['is_active' => false]);
        $this->api('POST', "transfers/{$transfer->id}/ship")->assertUnprocessable();
        $this->assertSame('approved', $transfer->fresh()->status->value);
    }

    public function test_scans_accumulate_without_exposing_book_quantities(): void
    {
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $branch, 5);
        $count = app(StocktakeService::class)->create(['branch_id' => $branch->id], $this->staff->id);
        $counter = $this->branchStaff($branch, ['stocktakes.update']);
        $controller = app(PosStockController::class);
        foreach ([1, 2] as $quantity) {
            $request = Request::create('/', 'POST', ['barcode' => $variant->barcode, 'qty' => $quantity]);
            $request->setUserResolver(fn () => $counter);
            $result = $controller->scan($request, $count)->getData(true);
            $this->assertArrayNotHasKey('system_qty', $result);
        }
        $this->assertSame('3.0000', $count->items()->firstOrFail()->counted_qty);
        $this->assertSame('5.0000', $count->items()->firstOrFail()->system_qty);
    }

    public function test_production_average_includes_goods_in_transit(): void
    {
        $from = Branch::factory()->create();
        $to = Branch::factory()->create();
        $variant = ProductVariant::factory()->create(['cost_price' => 10]);
        $this->movement($variant, $from, 10);
        $transfer = app(TransferService::class)->create([
            'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 6]],
        ], $this->staff->id);
        app(TransferService::class)->approve($transfer, [], $this->staff->id);
        app(TransferService::class)->ship($transfer, [], $this->staff->id);
        app(ProductionReceiptService::class)->receive([
            'branch_id' => $from->id, 'batch_number' => 'IN-TRANSIT-COST',
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 10, 'unit_cost' => 20]],
        ], $this->staff->id, 'in-transit-cost');

        $this->assertSame('15.0000', $variant->fresh()->cost_price);
    }

    private function branchStaff(Branch $branch, array $permissions): User
    {
        $staff = User::factory()->create(['branch_id' => $branch->id]);
        foreach ($permissions as $name) {
            $staff->givePermissionTo(Permission::findOrCreate($name, 'staff'));
        }

        return $staff;
    }

    private function movement(ProductVariant $variant, Branch $branch, float $qty, string $type = 'adjustment_in'): void
    {
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id,
            'type' => $qty > 0 && $type === 'adjustment_in' ? 'adjustment_in' : $type,
            'affected_bucket' => 'on_hand', 'quantity' => $qty, 'created_by' => $this->staff->id,
        ]);
    }

    private function api(string $method, string $path, array $data = [], ?User $staff = null, array $headers = []): TestResponse
    {
        Auth::forgetGuards();

        if (! isset($headers['Idempotency-Key']) && ! isset($this->defaultHeaders['Idempotency-Key'])) {
            $headers['Idempotency-Key'] = (string) Str::uuid();
        }

        return $this->withToken(($staff ?? $this->staff)->createToken('exec-review')->plainTextToken)
            ->json($method, '/api/v1/admin/'.$path, $data, $headers);
    }
}
