<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Inventory;

use App\Enums\Inventory\StockMovementType;
use App\Models\Branch;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Stocktake;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * task.txt §14 (approve differences)، §16 (scan_id)، §17 (recount after drift).
 */
class TaskTxtInventoryStocktakeTest extends TaskTxtInventoryCase
{
    private function makeTake(string $token, Branch $branch, array $items): array
    {
        return $this->requestWithToken($token, 'POST', '/api/v1/admin/stocktakes', [
            'branch_id' => $branch->id, 'items' => $items,
        ])->assertCreated()->json('data');
    }

    // ---------- §14a: loss / gain / zero-diff ----------

    public function test_approve_posts_loss_gain_or_nothing(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = Branch::factory()->create(['is_active' => true]);

        // Loss: system 10, counted 8.
        $vLoss = ProductVariant::factory()->create();
        $this->seedStock($token, $branch, $vLoss, 10);
        $loss = $this->makeTake($token, $branch, [['product_variant_id' => $vLoss->id, 'counted_qty' => 8]]);
        $approved = $this->requestWithToken($token, 'POST', "/api/v1/admin/stocktakes/{$loss['id']}/approve", [])
            ->assertOk()->json('data');
        $this->assertSame('approved', $approved['status']);
        $this->assertNotNull(Stocktake::find($loss['id'])->approved_by);
        $this->assertNotNull(Stocktake::find($loss['id'])->approved_at);
        $this->assertSame('8.0000', $this->level($vLoss->id, $branch->id)->on_hand);
        $move = StockMovement::query()->where('type', StockMovementType::StocktakeLoss)->firstOrFail();
        $this->assertSame('-2.0000', $move->quantity);
        $this->assertSame(0, DB::table('stocktake_scans')->where('stocktake_id', $loss['id'])->count());

        // Gain: system 10, counted 12.
        $vGain = ProductVariant::factory()->create();
        $this->seedStock($token, $branch, $vGain, 10);
        $gain = $this->makeTake($token, $branch, [['product_variant_id' => $vGain->id, 'counted_qty' => 12]]);
        $this->requestWithToken($token, 'POST', "/api/v1/admin/stocktakes/{$gain['id']}/approve", [])->assertOk();
        $this->assertSame('12.0000', $this->level($vGain->id, $branch->id)->on_hand);
        $this->assertSame('2.0000', StockMovement::query()->where('type', StockMovementType::StocktakeGain)->firstOrFail()->quantity);

        // Zero difference: no movement.
        $vEven = ProductVariant::factory()->create();
        $this->seedStock($token, $branch, $vEven, 10);
        $even = $this->makeTake($token, $branch, [['product_variant_id' => $vEven->id, 'counted_qty' => 10]]);
        $count = StockMovement::query()->count();
        $this->requestWithToken($token, 'POST', "/api/v1/admin/stocktakes/{$even['id']}/approve", [])->assertOk();
        $this->assertSame($count, StockMovement::query()->count());
    }

    // ---------- §14b/c: uncounted / empty ----------

    public function test_approve_requires_every_item_counted(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $this->seedStock($token, $branch, $variant, 5);

        $take = $this->makeTake($token, $branch, [['product_variant_id' => $variant->id]]);
        $this->requestWithToken($token, 'POST', "/api/v1/admin/stocktakes/{$take['id']}/approve", [])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'لا يمكن الاعتماد: توجد عناصر لم تُعدّ بعد.');
    }

    public function test_approve_rejects_empty_stocktake(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = Branch::factory()->create(['is_active' => true]);

        $take = $this->makeTake($token, $branch, []);
        $this->requestWithToken($token, 'POST', "/api/v1/admin/stocktakes/{$take['id']}/approve", [])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'لا يمكن اعتماد جرد بلا عناصر.');
    }

    // ---------- §14d: below reserved + damaged ----------

    public function test_approve_rejects_count_below_reserved_plus_damaged(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $from = Branch::factory()->create(['is_active' => true]);
        $to = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $this->seedStock($token, $from, $variant, 10);

        // Reserve 3, damage 2 → counted 4 < 5 rejected.
        $transfer = $this->requestWithToken($token, 'POST', '/api/v1/admin/transfers', [
            'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 3]],
        ])->assertCreated()->json('data');
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/approve", [
            'expected_version' => $transfer['version'],
        ])->assertOk();
        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $from->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'mark_damaged', 'quantity' => 2]],
        ])->assertCreated();

        $take = $this->makeTake($token, $from, [['product_variant_id' => $variant->id, 'counted_qty' => 4]]);
        $this->requestWithToken($token, 'POST', "/api/v1/admin/stocktakes/{$take['id']}/approve", [])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'العد الفعلي أقل من المحجوز والتالف؛ راجع الحجوزات وسوِّ التالف قبل إعادة العد والاعتماد.');
    }

    // ---------- §14e/f: stale policy ----------

    public function test_stale_count_rejected_with_recount_ids_by_default(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $this->seedStock($token, $branch, $variant, 10);

        $take = $this->makeTake($token, $branch, [['product_variant_id' => $variant->id, 'counted_qty' => 10]]);

        // A sale moves on_hand after the count.
        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'decrease', 'quantity' => 2]],
        ])->assertCreated();

        $moves = StockMovement::query()->count();
        $response = $this->requestWithToken($token, 'POST', "/api/v1/admin/stocktakes/{$take['id']}/approve", []);
        $response->assertUnprocessable()
            ->assertJsonPath('message', 'توجد أصناف تغيّر رصيدها بعد عدّها وتحتاج إعادة عد قبل الاعتماد.');
        $this->assertNotEmpty($response->json('errors.recount_item_ids'));
        $this->assertContains($variant->id, $response->json('errors.recount_variant_ids'));
        $this->assertSame($moves, StockMovement::query()->count());
        $this->assertSame('8.0000', $this->level($variant->id, $branch->id)->on_hand);
    }

    public function test_apply_difference_policy_applies_delta_to_current_balance(): void
    {
        config(['inventory.stocktake_stale_policy' => 'apply_difference']);

        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $this->seedStock($token, $branch, $variant, 10);

        $take = $this->makeTake($token, $branch, [['product_variant_id' => $variant->id, 'counted_qty' => 10]]);
        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'decrease', 'quantity' => 2]],
        ])->assertCreated();

        $this->requestWithToken($token, 'POST', "/api/v1/admin/stocktakes/{$take['id']}/approve", [])->assertOk();
        // Zero difference applied onto the drifted balance: it stays where the sale left it.
        $this->assertSame('8.0000', $this->level($variant->id, $branch->id)->on_hand);
    }

    // ---------- §14g: locked sessions ----------

    public function test_locked_stocktake_cannot_be_approved_again(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $this->seedStock($token, $branch, $variant, 5);

        $take = $this->makeTake($token, $branch, [['product_variant_id' => $variant->id, 'counted_qty' => 5]]);
        $this->requestWithToken($token, 'POST', "/api/v1/admin/stocktakes/{$take['id']}/approve", [])->assertOk();
        $this->requestWithToken($token, 'POST', "/api/v1/admin/stocktakes/{$take['id']}/approve", [])->assertUnprocessable();

        $take2 = $this->makeTake($token, $branch, [['product_variant_id' => $variant->id, 'counted_qty' => 5]]);
        $this->requestWithToken($token, 'PUT', "/api/v1/admin/stocktakes/{$take2['id']}", ['status' => 'cancelled'])->assertOk();
        $this->requestWithToken($token, 'POST', "/api/v1/admin/stocktakes/{$take2['id']}/approve", [])->assertUnprocessable();
    }

    // ---------- §16a/c: scan idempotency ----------

    private function scanSetup(Branch $branch, ProductVariant $variant): array
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $adminToken = $this->tokenFor($admin);
        $take = $this->makeTake($adminToken, $branch, [['product_variant_id' => $variant->id, 'counted_qty' => 0]]);
        [, $token] = $this->posCashierWithShift($branch, ['stocktakes.update']);

        return [$take, $token];
    }

    public function test_same_scan_id_counts_exactly_once(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create(['barcode' => '6220000016101', 'is_active' => true]);
        [$take, $token] = $this->scanSetup($branch, $variant);
        $scanId = (string) \Illuminate\Support\Str::uuid();

        $first = $this->posWithToken($token)->postJson("/api/v1/pos/stocktakes/{$take['id']}/scan", [
            'barcode' => '6220000016101', 'qty' => 2, 'scan_id' => $scanId,
        ])->assertOk()->json();
        $this->assertSame('2.0000', $first['counted_qty']);
        $this->assertArrayNotHasKey('duplicate', $first);

        $second = $this->posWithToken($token)->postJson("/api/v1/pos/stocktakes/{$take['id']}/scan", [
            'barcode' => '6220000016101', 'qty' => 2, 'scan_id' => $scanId,
        ])->assertOk()->json();
        $this->assertSame('2.0000', $second['counted_qty']);
        $this->assertTrue($second['duplicate']);

        $this->assertSame(1, DB::table('stocktake_scans')->where('stocktake_id', $take['id'])->count());
    }

    public function test_different_scan_ids_accumulate(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create(['barcode' => '6220000016201', 'is_active' => true]);
        [$take, $token] = $this->scanSetup($branch, $variant);

        $this->posWithToken($token)->postJson("/api/v1/pos/stocktakes/{$take['id']}/scan", [
            'barcode' => '6220000016201', 'qty' => 2, 'scan_id' => (string) \Illuminate\Support\Str::uuid(),
        ])->assertOk();
        $second = $this->posWithToken($token)->postJson("/api/v1/pos/stocktakes/{$take['id']}/scan", [
            'barcode' => '6220000016201', 'qty' => 3, 'scan_id' => (string) \Illuminate\Support\Str::uuid(),
        ])->assertOk()->json();
        $this->assertSame('5.0000', $second['counted_qty']);
    }

    // ---------- §16d: cancel clears scans ----------

    public function test_cancel_clears_scan_rows(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create(['barcode' => '6220000016301', 'is_active' => true]);
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $adminToken = $this->tokenFor($admin);
        $take = $this->makeTake($adminToken, $branch, [['product_variant_id' => $variant->id, 'counted_qty' => 0]]);
        [, $token] = $this->posCashierWithShift($branch, ['stocktakes.update']);

        $this->posWithToken($token)->postJson("/api/v1/pos/stocktakes/{$take['id']}/scan", [
            'barcode' => '6220000016301', 'qty' => 1, 'scan_id' => (string) \Illuminate\Support\Str::uuid(),
        ])->assertOk();
        $this->assertSame(1, DB::table('stocktake_scans')->where('stocktake_id', $take['id'])->count());

        $this->requestWithToken($adminToken, 'PUT', "/api/v1/admin/stocktakes/{$take['id']}", ['status' => 'cancelled'])->assertOk();
        $this->assertSame(0, DB::table('stocktake_scans')->where('stocktake_id', $take['id'])->count());
    }

    // ---------- §16e: zero scan rejected without side effects ----------

    public function test_zero_quantity_scan_rejected_at_service_level(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create(['is_active' => true]);
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $service = app(\App\Services\Inventory\StocktakeService::class);
        $take = $service->create(['branch_id' => $branch->id], $admin->id);

        try {
            $service->incrementItemCount($take, $variant->id, 0, $admin->id);
            $this->fail('zero scan must be rejected');
        } catch (\App\Exceptions\BusinessException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, DB::table('stocktake_scans')->where('stocktake_id', $take->id)->count());
        $this->assertSame(0, \App\Models\StocktakeItem::query()->where('stocktake_id', $take->id)->count());
    }

    // ---------- §17: rescan after drift + recount ----------

    public function test_rescan_after_stock_drift_requires_recount(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $adminToken = $this->tokenFor($admin);
        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create(['barcode' => '6220000016401', 'is_active' => true]);
        $this->seedStock($adminToken, $branch, $variant, 10);

        $take = $this->makeTake($adminToken, $branch, [['product_variant_id' => $variant->id, 'counted_qty' => 3]]);
        [, $token] = $this->posCashierWithShift($branch, ['stocktakes.update']);

        // A sale moves the balance after the first count.
        $this->requestWithToken($adminToken, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'decrease', 'quantity' => 1]],
        ])->assertCreated();

        $this->posWithToken($token)->postJson("/api/v1/pos/stocktakes/{$take['id']}/scan", [
            'barcode' => '6220000016401', 'qty' => 1,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'تغيّر رصيد هذا الصنف أثناء العد؛ أعد عدّه من جديد قبل المتابعة.');

        // Recount refreshes the snapshot and accepts the scan.
        $this->requestWithToken($adminToken, 'PATCH', "/api/v1/admin/stocktakes/{$take['id']}/items", [
            'items' => [['product_variant_id' => $variant->id, 'counted_qty' => 3, 'recount' => true]],
        ])->assertOk();

        $item = \App\Models\StocktakeItem::query()
            ->where('stocktake_id', $take['id'])
            ->where('product_variant_id', $variant->id)
            ->firstOrFail();
        $this->assertSame('9.0000', $item->system_qty);
        $this->assertSame(StockMovement::query()->latest('id')->firstOrFail()->id, (int) $item->snapshot_movement_id);
    }

    // ---------- §25: voice count at the POS endpoint ----------

    public function test_pos_scan_rejects_unparsable_and_zero_voice_counts(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $adminToken = $this->tokenFor($admin);
        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create(['barcode' => '6220000016501', 'is_active' => true]);

        $take = $this->makeTake($adminToken, $branch, [['product_variant_id' => $variant->id, 'counted_qty' => 0]]);
        [, $token] = $this->posCashierWithShift($branch, ['stocktakes.update']);

        $this->posWithToken($token)->postJson("/api/v1/pos/stocktakes/{$take['id']}/scan", [
            'barcode' => '6220000016501', 'qty_words' => 'كلام',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'العدد المنطوق مش واضح؛ قوله تاني أو اكتبه رقمًا.');

        $this->posWithToken($token)->postJson("/api/v1/pos/stocktakes/{$take['id']}/scan", [
            'barcode' => '6220000016501', 'qty_words' => 'صفر',
        ])->assertUnprocessable();

        // A valid voice count works.
        $ok = $this->posWithToken($token)->postJson("/api/v1/pos/stocktakes/{$take['id']}/scan", [
            'barcode' => '6220000016501', 'qty_words' => 'اتنين وعشرين',
        ])->assertOk()->json();
        $this->assertSame('22.0000', $ok['counted_qty']);
    }
}
