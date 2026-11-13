<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Models\PosShift;
use App\Services\Pos\PosService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class Task3Slice5CatalogCacheAndMigrationTest extends PosScenarioFixture
{
    use LazilyRefreshDatabase;

    private function as(string $token): static
    {
        Auth::forgetGuards();

        return $this->withToken($token);
    }

    public function test_create_sale_invalidates_catalog_version_cache(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.access', 'pos.sales.create']);
        $posService = app(PosService::class);

        // 1. Warm cache
        $versionBefore = $posService->computeCatalogVersion($this->branch->id);
        $epoch = (int) Cache::get(PosService::CATALOG_VERSION_EPOCH_KEY, 0);
        $this->assertTrue(Cache::has("pos:catalog_version:{$this->branch->id}:{$epoch}"));

        // 2. Perform sale
        $this->as($token)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'payment_method' => 'cash',
            'cash_amount' => 100.0,
            'card_amount' => 0.0,
        ], $this->idem())->assertCreated();

        // 3. Cache must be invalidated (either forgotten or version changed)
        $this->assertFalse(Cache::has("pos:catalog_version:{$this->branch->id}:{$epoch}"));
    }

    public function test_process_return_invalidates_catalog_version_cache(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.access', 'pos.sales.create', 'pos.sales.refund']);
        $posService = app(PosService::class);

        // 1. Create sale
        $sale = $this->as($token)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 2]],
            'payment_method' => 'cash',
            'cash_amount' => 200.0,
            'card_amount' => 0.0,
        ], $this->idem())->assertCreated()->json('data');

        // 2. Warm cache
        $posService->computeCatalogVersion($this->branch->id);
        $epoch = (int) Cache::get(PosService::CATALOG_VERSION_EPOCH_KEY, 0);
        $this->assertTrue(Cache::has("pos:catalog_version:{$this->branch->id}:{$epoch}"));

        // 3. Process return
        $this->as($token)->postJson("/api/v1/pos/sales/{$sale['id']}/return", [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
        ], $this->idem())->assertOk();

        // 4. Cache must be invalidated
        $this->assertFalse(Cache::has("pos:catalog_version:{$this->branch->id}:{$epoch}"));
    }

    public function test_receive_transfer_invalidates_catalog_cache_for_both_branches(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.access', 'transfers.receive']);
        $posService = app(PosService::class);

        $branch2 = \App\Models\Branch::factory()->create(['is_active' => true]);

        // Warm caches for both branches
        $posService->computeCatalogVersion($this->branch->id);
        $posService->computeCatalogVersion($branch2->id);

        $epoch = (int) Cache::get(PosService::CATALOG_VERSION_EPOCH_KEY, 0);
        $this->assertTrue(Cache::has("pos:catalog_version:{$this->branch->id}:{$epoch}"));
        $this->assertTrue(Cache::has("pos:catalog_version:{$branch2->id}:{$epoch}"));

        // Create transfer from branch2 to branch1
        $transfer = \App\Models\StockTransfer::create([
            'transfer_number' => 'TRF-TEST-001',
            'from_branch_id' => $branch2->id,
            'to_branch_id' => $this->branch->id,
            'status' => 'shipped',
            'created_by' => $this->cashier->id,
        ]);
        $item = \App\Models\StockTransferItem::create([
            'stock_transfer_id' => $transfer->id,
            'product_variant_id' => $this->variant->id,
            'requested_qty' => 5,
            'approved_qty' => 5,
            'shipped_qty' => 5,
            'received_qty' => 0,
        ]);

        app(\App\Services\Inventory\InventoryService::class)->recordMovement([
            'product_variant_id' => $this->variant->id,
            'branch_id' => $this->branch->id,
            'type' => \App\Enums\Inventory\StockMovementType::TransferInTransit,
            'affected_bucket' => \App\Enums\Inventory\StockBucket::InTransit,
            'quantity' => 5,
            'created_by' => $this->cashier->id,
        ]);

        $this->as($token)->postJson("/api/v1/pos/transfers/{$transfer->id}/receive", [
            'items' => [
                ['id' => $item->id, 'received_qty' => 5],
            ],
        ])->assertOk();

        // Both branch caches should be invalidated
        $this->assertFalse(Cache::has("pos:catalog_version:{$this->branch->id}:{$epoch}"));
        $this->assertFalse(Cache::has("pos:catalog_version:{$branch2->id}:{$epoch}"));
    }

    public function test_orders_table_allows_non_pos_channel_without_tender_constraint_violation(): void
    {
        // Non-pos orders (e.g. online, wholesale) might not set exchange_credit or tender sums
        $order = \App\Models\Order::create([
            'order_number' => 'ORD-WHOLESALE-001',
            'channel' => 'wholesale',
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'currency' => 'EGP',
            'total_amount' => 500.0,
            'tax_amount' => 0.0,
            'shipping_amount' => 0.0,
            'discount_amount' => 0.0,
            'created_by' => $this->cashier->id,
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'order_number' => 'ORD-WHOLESALE-001',
            'channel' => 'wholesale',
        ]);
    }
}
