<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Inventory;

use App\Enums\Catalog\ProductStatus;
use App\Enums\Inventory\StockBucket;
use App\Enums\Inventory\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Treasury;
use App\Models\User;
use App\Enums\Finance\TreasuryType;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\StocktakeService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Retried POS scans with the same scan_id must count exactly once.
 */
class StocktakeScanIdempotencyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private User $cashier;

    private ProductVariant $variant;

    private Treasury $drawer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);

        $this->branch = Branch::create([
            'name' => 'Scan Branch', 'code' => 'BR-SCAN', 'type' => 'branch', 'is_active' => true,
        ]);
        $this->cashier = User::create([
            'name' => 'Scanner', 'email' => 'scanner@example.com', 'phone' => '+201011113333',
            'password' => Hash::make('secret123'), 'branch_id' => $this->branch->id, 'is_active' => true,
        ]);
        $this->cashier->assignRole(Role::findOrCreate('cashier', 'staff'));
        foreach (['pos.access', 'stocktakes.update', 'stocktakes.create'] as $name) {
            $this->cashier->givePermissionTo(Permission::findOrCreate($name, 'staff'));
        }
        $this->drawer = Treasury::create([
            'name' => 'Scan Drawer', 'code' => 'DRW-SCAN', 'branch_id' => $this->branch->id,
            'type' => TreasuryType::PosDrawer, 'current_balance' => 0, 'is_active' => true,
        ]);
        Treasury::create([
            'name' => 'Scan Safe', 'code' => 'SAFE-SCAN', 'branch_id' => $this->branch->id,
            'type' => TreasuryType::MainSafe, 'current_balance' => 1000, 'is_active' => true,
        ]);

        $brand = Brand::create(['name_ar' => 'ب', 'name_en' => 'B', 'slug' => 'brand-scan', 'is_active' => true]);
        $cat = Category::create(['name_ar' => 'ش', 'name_en' => 'C', 'slug' => 'cat-scan', 'is_active' => true]);
        $prod = Product::create([
            'code' => 'PRD-SCAN', 'name_ar' => 'شنطة', 'name_en' => 'Bag', 'slug' => 'bag-scan',
            'status' => ProductStatus::Published, 'brand_id' => $brand->id, 'category_id' => $cat->id,
            'is_active' => true, 'published_at' => now(),
        ]);
        $this->variant = ProductVariant::create([
            'product_id' => $prod->id, 'sku' => 'SCAN-001', 'barcode' => '622999000001',
            'is_active' => true,
        ]);
    }

    public function test_retry_with_same_scan_id_counts_once(): void
    {
        $stocktake = app(StocktakeService::class)->create([
            'branch_id' => $this->branch->id, 'items' => [],
        ], $this->cashier->id);
        $token = $this->cashier->createToken('pos', ['pos'])->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/pos/shifts/open', [
            'treasury_id' => $this->drawer->id, 'opening_cash' => 100.00,
        ])->assertCreated();
        $scanId = (string) Str::uuid();

        $first = $this->withToken($token)->postJson("/api/v1/pos/stocktakes/{$stocktake->id}/scan", [
            'barcode' => '622999000001', 'qty' => 2, 'scan_id' => $scanId,
        ])->assertOk();
        $first->assertJsonPath('counted_qty', '2.0000');
        $first->assertJsonMissingPath('duplicate');

        // Simulated retry after a timeout: same scan_id must not double-count.
        $retry = $this->withToken($token)->postJson("/api/v1/pos/stocktakes/{$stocktake->id}/scan", [
            'barcode' => '622999000001', 'qty' => 2, 'scan_id' => $scanId,
        ])->assertOk();
        $retry->assertJsonPath('counted_qty', '2.0000');
        $retry->assertJsonPath('duplicate', true);

        // A genuinely new scan still counts.
        $second = $this->withToken($token)->postJson("/api/v1/pos/stocktakes/{$stocktake->id}/scan", [
            'barcode' => '622999000001', 'qty' => 1, 'scan_id' => (string) Str::uuid(),
        ])->assertOk();
        $second->assertJsonPath('counted_qty', '3.0000');

        $this->assertSame(2, DB::table('stocktake_scans')->where('stocktake_id', $stocktake->id)->count());
    }

    public function test_rescan_after_intervening_movement_requires_recount(): void
    {
        $service = app(StocktakeService::class);
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $this->variant->id, 'branch_id' => $this->branch->id,
            'type' => StockMovementType::AdjustmentIn, 'affected_bucket' => StockBucket::OnHand,
            'quantity' => 10, 'created_by' => $this->cashier->id,
        ]);

        $stocktake = $service->create(['branch_id' => $this->branch->id, 'items' => []], $this->cashier->id);
        $service->incrementItemCount($stocktake, $this->variant->id, 10, $this->cashier->id);
        $this->assertSame(10.0, (float) $stocktake->fresh()->items()->firstOrFail()->counted_qty);

        // A sale lands after the first scan: rescanning must not silently stack.
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $this->variant->id, 'branch_id' => $this->branch->id,
            'type' => StockMovementType::Sale, 'affected_bucket' => StockBucket::OnHand,
            'quantity' => -1, 'created_by' => $this->cashier->id,
        ]);

        try {
            $service->incrementItemCount($stocktake->fresh(), $this->variant->id, 1, $this->cashier->id);
            $this->fail('Expected a stale-scan rejection.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('أعد عدّه', $e->getMessage());
        }
        $this->assertSame(10.0, (float) $stocktake->fresh()->items()->firstOrFail()->counted_qty);

        // After a recount the snapshot cursor advances and the count restarts from zero.
        $service->upsertSomeItems($stocktake->fresh(), [
            ['product_variant_id' => $this->variant->id, 'recount' => true],
        ], $this->cashier->id);
        $this->assertSame(0.0, (float) $stocktake->fresh()->items()->firstOrFail()->counted_qty);
        $service->incrementItemCount($stocktake->fresh(), $this->variant->id, 1, $this->cashier->id);
        $this->assertSame(1.0, (float) $stocktake->fresh()->items()->firstOrFail()->counted_qty);
    }

    public function test_reused_scan_id_with_different_payload_is_rejected(): void
    {
        $service = app(StocktakeService::class);
        $stocktake = $service->create([
            'branch_id' => $this->branch->id, 'items' => [],
        ], $this->cashier->id);
        $scanId = (string) Str::uuid();
        $service->incrementItemCount($stocktake, $this->variant->id, 2, $this->cashier->id, $scanId);

        // Same scan_id with a different quantity must not be swallowed as a duplicate.
        try {
            $service->incrementItemCount($stocktake->fresh(), $this->variant->id, 5, $this->cashier->id, $scanId);
            $this->fail('Mismatched scan replay must be rejected.');
        } catch (BusinessException $e) {
            $this->assertSame(409, $e->status);
        }

        // Same scan_id for a different variant must also be rejected.
        $other = ProductVariant::create([
            'product_id' => $this->variant->product_id, 'sku' => 'SCAN-002',
            'barcode' => '622999000002', 'is_active' => true,
        ]);
        try {
            $service->incrementItemCount($stocktake->fresh(), $other->id, 2, $this->cashier->id, $scanId);
            $this->fail('Cross-variant scan replay must be rejected.');
        } catch (BusinessException $e) {
            $this->assertSame(409, $e->status);
        }

        $this->assertSame(2.0, (float) $stocktake->fresh()->items()->firstOrFail()->counted_qty);
    }

    public function test_recount_without_explicit_count_restarts_from_zero(): void
    {
        $service = app(StocktakeService::class);
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $this->variant->id, 'branch_id' => $this->branch->id,
            'type' => StockMovementType::AdjustmentIn, 'affected_bucket' => StockBucket::OnHand,
            'quantity' => 10, 'created_by' => $this->cashier->id,
        ]);

        $stocktake = $service->create(['branch_id' => $this->branch->id, 'items' => []], $this->cashier->id);
        $service->incrementItemCount($stocktake, $this->variant->id, 10, $this->cashier->id);

        // A sale lands after the count; the baseline is now stale.
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $this->variant->id, 'branch_id' => $this->branch->id,
            'type' => StockMovementType::Sale, 'affected_bucket' => StockBucket::OnHand,
            'quantity' => -1, 'created_by' => $this->cashier->id,
        ]);

        // Recount without an explicit count restarts from zero against the fresh baseline.
        $service->upsertSomeItems($stocktake->fresh(), [
            ['product_variant_id' => $this->variant->id, 'recount' => true],
        ], $this->cashier->id);
        $item = $stocktake->fresh()->items()->firstOrFail();
        $this->assertSame(0.0, (float) $item->counted_qty);
        $this->assertSame(9.0, (float) $item->system_qty);

        // Re-scanning the full shelf matches the fresh baseline: no phantom gain.
        $service->incrementItemCount($stocktake->fresh(), $this->variant->id, 9, $this->cashier->id);
        $service->approve($stocktake->fresh(), $this->cashier->id);
        $this->assertSame('9.0000', \App\Models\StockLevel::where('product_variant_id', $this->variant->id)->firstOrFail()->on_hand);
        $this->assertSame(0, \App\Models\StockMovement::whereIn('type', [
            StockMovementType::StocktakeGain->value, StockMovementType::StocktakeLoss->value,
        ])->count());
    }

    public function test_correcting_a_count_after_movement_demands_explicit_recount(): void
    {
        $service = app(StocktakeService::class);
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $this->variant->id, 'branch_id' => $this->branch->id,
            'type' => StockMovementType::AdjustmentIn, 'affected_bucket' => StockBucket::OnHand,
            'quantity' => 10, 'created_by' => $this->cashier->id,
        ]);

        $stocktake = $service->create(['branch_id' => $this->branch->id, 'items' => [
            ['product_variant_id' => $this->variant->id, 'counted_qty' => 10],
        ]], $this->cashier->id);

        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $this->variant->id, 'branch_id' => $this->branch->id,
            'type' => StockMovementType::Sale, 'affected_bucket' => StockBucket::OnHand,
            'quantity' => -1, 'created_by' => $this->cashier->id,
        ]);

        // Silently re-baselining a corrected count against post-sale stock is rejected.
        try {
            $service->update($stocktake->fresh(), ['items' => [
                ['product_variant_id' => $this->variant->id, 'counted_qty' => 12],
            ]], $this->cashier->id);
            $this->fail('Correcting a stale count must demand an explicit recount.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('أعد عدّه', $e->getMessage());
        }
        $this->assertSame(10.0, (float) $stocktake->fresh()->items()->firstOrFail()->counted_qty);

        // The same correction with an explicit recount is accepted against the fresh baseline.
        $updated = $service->update($stocktake->fresh(), ['items' => [
            ['product_variant_id' => $this->variant->id, 'counted_qty' => 12, 'recount' => true],
        ]], $this->cashier->id);
        $this->assertSame(12.0, (float) $updated->items()->firstOrFail()->counted_qty);
        $this->assertSame(9.0, (float) $updated->items()->firstOrFail()->system_qty);
    }

    public function test_scans_are_cleaned_up_after_cancel(): void
    {
        $service = app(StocktakeService::class);
        $stocktake = $service->create(['branch_id' => $this->branch->id, 'items' => []], $this->cashier->id);
        $service->incrementItemCount($stocktake, $this->variant->id, 1, $this->cashier->id, (string) Str::uuid());

        $this->assertSame(1, DB::table('stocktake_scans')->where('stocktake_id', $stocktake->id)->count());

        $service->update($stocktake, ['status' => 'cancelled'], $this->cashier->id);

        $this->assertSame(0, DB::table('stocktake_scans')->where('stocktake_id', $stocktake->id)->count());
    }
}
