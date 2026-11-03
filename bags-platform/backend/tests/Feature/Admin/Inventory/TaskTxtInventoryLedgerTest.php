<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Inventory;

use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\InventoryIdempotencyKey;
use App\Models\InventoryReconcileRun;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Services\InventoryService;
use App\Services\ProductionReceiptService;
use App\Services\StocktakeService;
use App\Services\TransferService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * task.txt §1 (ledger invariant + reconcile)، §2 (negative guards)،
 * §3 (multi-item atomicity)، §23 (reconcile tamper)، §32 (prune keys)،
 * §26 (adjustment validation)، §27 (inactive entities)، §28 (movements filters).
 */
class TaskTxtInventoryLedgerTest extends TaskTxtInventoryCase
{
    // ---------- §1: ledger/stock invariant under a random valid sequence ----------

    public function test_random_valid_sequence_keeps_ledger_and_reconcile_clean(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $branches = [$this->freshBranch(), $this->freshBranch()];
        $variants = [ProductVariant::factory()->create(), ProductVariant::factory()->create()];

        $onHand = $reserved = $damaged = [];
        foreach ($branches as $b) {
            foreach ($variants as $v) {
                $onHand[$v->id][$b->id] = 0.0;
                $reserved[$v->id][$b->id] = 0.0;
                $damaged[$v->id][$b->id] = 0.0;
            }
        }

        mt_srand(20261103);
        $inventory = app(InventoryService::class);
        $transfers = app(TransferService::class);
        $stocktakes = app(StocktakeService::class);
        $receipts = app(ProductionReceiptService::class);

        for ($i = 0; $i < 200; $i++) {
            $variant = $variants[mt_rand(0, 1)];
            $branch = $branches[mt_rand(0, 1)];
            $vid = $variant->id;
            $bid = $branch->id;
            $available = $onHand[$vid][$bid] - $reserved[$vid][$bid] - $damaged[$vid][$bid];
            $roll = mt_rand(1, 100);

            if ($roll <= 40) {
                $q = mt_rand(1, 5);
                $inventory->applyAdjustments($this->adjPayload($bid, $vid, 'increase', $q), $admin->id);
                $onHand[$vid][$bid] += $q;
            } elseif ($roll <= 55 && $available >= 1) {
                $q = min(mt_rand(1, 3), (int) $available);
                $inventory->applyAdjustments($this->adjPayload($bid, $vid, 'decrease', $q), $admin->id);
                $onHand[$vid][$bid] -= $q;
            } elseif ($roll <= 65 && $available >= 1) {
                $q = min(mt_rand(1, 3), (int) $available);
                $inventory->applyAdjustments($this->adjPayload($bid, $vid, 'mark_damaged', $q), $admin->id);
                $damaged[$vid][$bid] += $q;
            } elseif ($roll <= 70 && $damaged[$vid][$bid] >= 1) {
                $q = min(mt_rand(1, 2), (int) $damaged[$vid][$bid]);
                $inventory->applyAdjustments($this->adjPayload($bid, $vid, 'restore_damaged', $q), $admin->id);
                $damaged[$vid][$bid] -= $q;
            } elseif ($roll <= 75 && $damaged[$vid][$bid] >= 1) {
                $q = min(mt_rand(1, 2), (int) $damaged[$vid][$bid]);
                $inventory->applyAdjustments($this->adjPayload($bid, $vid, 'write_off_damaged', $q), $admin->id);
                $damaged[$vid][$bid] -= $q;
                $onHand[$vid][$bid] -= $q;
            } elseif ($roll <= 85 && $available >= 2) {
                // Full transfer cycle between the two branches.
                $other = $branches[$bid === $branches[0]->id ? 1 : 0];
                $q = min(mt_rand(1, 2), (int) $available);
                $transfer = $transfers->create([
                    'from_branch_id' => $bid,
                    'to_branch_id' => $other->id,
                    'items' => [['product_variant_id' => $vid, 'requested_qty' => $q]],
                ], $admin->id);
                $transfers->approve($transfer, [], $admin->id);
                $transfers->ship($transfer->fresh(), [], $admin->id);
                $transfers->receive($transfer->fresh(), [], $admin->id);
                $onHand[$vid][$bid] -= $q;
                $onHand[$vid][$other->id] += $q;
            } elseif ($roll <= 92) {
                // Zero-difference stocktake: count exactly what is on hand.
                $level = $inventory->levelFor($vid, $bid, null);
                $current = $level ? (float) $level->on_hand : 0.0;
                $take = $stocktakes->create([
                    'branch_id' => $bid,
                    'items' => [['product_variant_id' => $vid, 'counted_qty' => $current]],
                ], $admin->id);
                $stocktakes->approve($take, $admin->id);
            } else {
                $q = mt_rand(1, 3);
                $receipts->receive([
                    'branch_id' => $bid,
                    'batch_number' => 'PROP-'.$i.'-'.$vid,
                    'items' => [['product_variant_id' => $vid, 'quantity' => $q, 'unit_cost' => '4.0000']],
                ], $admin->id, 'prop-key-'.$i);
                $onHand[$vid][$bid] += $q;
            }
        }

        // No negative level anywhere.
        $this->assertSame(0, StockLevel::query()
            ->where(fn ($q) => $q->where('on_hand', '<', 0)
                ->orWhere('reserved', '<', 0)
                ->orWhere('damaged', '<', 0)
                ->orWhere('in_transit', '<', 0))
            ->count());

        // Reconcile must succeed with zero problems.
        $this->artisan('inventory:reconcile', ['--all-branches' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('سليمة');

        $this->assertGreaterThan(0, InventoryReconcileRun::query()->count());
        $this->assertSame(0, (int) InventoryReconcileRun::query()->sum('problem_count'));
    }

    private function adjPayload(int $branchId, int $variantId, string $type, float $qty): array
    {
        return [
            'branch_id' => $branchId,
            'items' => [['product_variant_id' => $variantId, 'adjustment_type' => $type, 'quantity' => $qty]],
        ];
    }

    private function freshBranch(): Branch
    {
        return Branch::factory()->create(['is_active' => true]);
    }

    // ---------- §2: negative balance / available guards ----------

    public function test_decrease_beyond_available_is_rejected_without_writes(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $from = $this->freshBranch();
        $to = $this->freshBranch();
        $variant = ProductVariant::factory()->create();

        $this->seedStock($token, $from, $variant, 10);

        // Reserve 8 via an approved transfer.
        $transfer = $this->requestWithToken($token, 'POST', '/api/v1/admin/transfers', [
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 8]],
        ])->assertCreated()->json('data');
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/approve", [
            'expected_version' => $transfer['version'],
        ])->assertOk();

        $before = StockMovement::query()->count();

        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $from->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'decrease', 'quantity' => 3]],
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'الكمية المتاحة للبيع (2) غير كافية لإتمام العملية.');

        $this->assertSame($before, StockMovement::query()->count());
        $level = $this->level($variant->id, $from->id);
        $this->assertSame('10.0000', $level->on_hand);
        $this->assertSame('8.0000', $level->reserved);
    }

    public function test_mark_damaged_beyond_available_is_rejected_without_writes(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $from = $this->freshBranch();
        $to = $this->freshBranch();
        $variant = ProductVariant::factory()->create();

        $this->seedStock($token, $from, $variant, 10);
        $transfer = $this->requestWithToken($token, 'POST', '/api/v1/admin/transfers', [
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 8]],
        ])->assertCreated()->json('data');
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/approve", [
            'expected_version' => $transfer['version'],
        ])->assertOk();

        $before = StockMovement::query()->count();

        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $from->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'mark_damaged', 'quantity' => 3]],
        ])->assertUnprocessable();

        $this->assertSame($before, StockMovement::query()->count());
        $this->assertSame('10.0000', $this->level($variant->id, $from->id)->on_hand);
        $this->assertSame('8.0000', $this->level($variant->id, $from->id)->reserved);
    }

    public function test_write_off_damaged_with_zero_damaged_writes_nothing(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = $this->freshBranch();
        $variant = ProductVariant::factory()->create();

        $this->seedStock($token, $branch, $variant, 10);
        $before = StockMovement::query()->count();

        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'write_off_damaged', 'quantity' => 3]],
        ])->assertUnprocessable();

        $this->assertSame($before, StockMovement::query()->count());
        $level = $this->level($variant->id, $branch->id);
        $this->assertSame('10.0000', $level->on_hand);
        $this->assertSame('0.0000', $level->damaged);
    }

    // ---------- §3: multi-item atomicity + key release on failure ----------

    public function test_failed_multi_item_adjustment_writes_nothing_and_releases_key(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = $this->freshBranch();
        $variant = ProductVariant::factory()->create();

        $this->seedStock($token, $branch, $variant, 10);

        $key = (string) Str::uuid();
        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [
                ['product_variant_id' => $variant->id, 'adjustment_type' => 'increase', 'quantity' => 5],
                ['product_variant_id' => $variant->id, 'adjustment_type' => 'decrease', 'quantity' => 100],
            ],
        ], ['Idempotency-Key' => $key])->assertUnprocessable();

        $this->assertSame(1, StockMovement::query()->count()); // only the seed
        $this->assertSame('10.0000', $this->level($variant->id, $branch->id)->on_hand);
        $this->assertSame(0, InventoryIdempotencyKey::query()->where('key', $key)->count());

        // Same key with valid data executes normally.
        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [
                ['product_variant_id' => $variant->id, 'adjustment_type' => 'increase', 'quantity' => 5],
            ],
        ], ['Idempotency-Key' => $key])->assertCreated();

        $this->assertSame('15.0000', $this->level($variant->id, $branch->id)->on_hand);
    }

    // ---------- §23: reconcile detects tampering ----------

    public function test_reconcile_flags_direct_balance_tampering(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = $this->freshBranch();
        $variant = ProductVariant::factory()->create();
        $this->seedStock($token, $branch, $variant, 10);

        DB::table('stock_levels')
            ->where('product_variant_id', $variant->id)
            ->where('branch_id', $branch->id)
            ->update(['on_hand' => 999]);

        $this->artisan('inventory:reconcile', ['--all-branches' => true])
            ->assertExitCode(1)
            ->expectsOutputToContain('فشلت');

        $run = InventoryReconcileRun::query()->latest('id')->firstOrFail();
        $this->assertGreaterThan(0, $run->problem_count);
        $this->assertSame('balance', $run->samples[0]['issue']);
    }

    public function test_reconcile_flags_broken_ledger_chain(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = $this->freshBranch();
        $variant = ProductVariant::factory()->create();
        $this->seedStock($token, $branch, $variant, 10);
        $this->seedStock($token, $branch, $variant, 5);

        $second = StockMovement::query()->orderByDesc('id')->firstOrFail();
        // Ledger rows are protected by a DB trigger; bypass it transaction-locally
        // to simulate tampering below the app (resets automatically at rollback).
        DB::statement("SET LOCAL session_replication_role = 'replica'");
        // Shift both balances by +1: the row stays internally consistent
        // (balance_after = balance_before + qty) but breaks the chain.
        DB::table('stock_movements')->where('id', $second->id)->update([
            'balance_before' => DB::raw('balance_before + 1'),
            'balance_after' => DB::raw('balance_after + 1'),
        ]);

        $this->artisan('inventory:reconcile', ['--all-branches' => true])->assertExitCode(1);

        $run = InventoryReconcileRun::query()->latest('id')->firstOrFail();
        $this->assertContains('chain', collect($run->samples)->pluck('issue')->all());
    }

    public function test_reconcile_flags_movement_without_level_row(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = $this->freshBranch();
        $variant = ProductVariant::factory()->create();
        $this->seedStock($token, $branch, $variant, 10);

        DB::table('stock_levels')
            ->where('product_variant_id', $variant->id)
            ->where('branch_id', $branch->id)
            ->delete();

        $this->artisan('inventory:reconcile', ['--all-branches' => true])->assertExitCode(1);

        $run = InventoryReconcileRun::query()->latest('id')->firstOrFail();
        $this->assertSame('missing_level', $run->samples[0]['issue']);
    }

    public function test_reconcile_rejects_bad_options(): void
    {
        $this->artisan('inventory:reconcile', ['--branch_id' => 'abc'])->assertExitCode(2);
        $this->artisan('inventory:reconcile', ['--branch_id' => '1', '--all-branches' => true])->assertExitCode(2);
        $this->artisan('inventory:reconcile', ['--limit' => '0'])->assertExitCode(2);
    }

    public function test_reconcile_incremental_and_prunes_old_runs(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = $this->freshBranch();
        $variant = ProductVariant::factory()->create();
        $this->seedStock($token, $branch, $variant, 10);

        $this->artisan('inventory:reconcile', ['--incremental' => true])->assertSuccessful();

        $oldId = DB::table('inventory_reconcile_runs')->insertGetId([
            'branch_id' => null, 'checked' => 1, 'problem_count' => 0, 'samples' => '[]',
            'created_at' => Carbon::now()->subDays(15),
        ]);
        $recentId = DB::table('inventory_reconcile_runs')->insertGetId([
            'branch_id' => null, 'checked' => 1, 'problem_count' => 0, 'samples' => '[]',
            'created_at' => Carbon::now()->subDays(2),
        ]);

        $this->artisan('inventory:reconcile', ['--all-branches' => true])->assertSuccessful();

        $this->assertNull(InventoryReconcileRun::query()->find($oldId));
        $this->assertNotNull(InventoryReconcileRun::query()->find($recentId));
    }

    // ---------- §32: prune idempotency keys ----------

    public function test_prune_idempotency_keys_deletes_only_expired_in_batches(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);

        DB::table('inventory_idempotency_keys')->truncate();
        $old = Carbon::now()->subDays(7)->subSecond();
        $fresh = Carbon::now()->subDays(7)->addSecond();
        DB::table('inventory_idempotency_keys')->insert([
            ['user_id' => $admin->id, 'key' => 'old-key', 'request_hash' => str_repeat('a', 64), 'created_at' => $old, 'updated_at' => $old],
            ['user_id' => $admin->id, 'key' => 'fresh-key', 'request_hash' => str_repeat('b', 64), 'created_at' => $fresh, 'updated_at' => $fresh],
        ]);
        // Bulk rows older than 7 days (batching path: 2500 rows).
        $bulk = [];
        for ($i = 0; $i < 2500; $i++) {
            $bulk[] = ['user_id' => $admin->id, 'key' => 'bulk-'.$i, 'request_hash' => str_repeat('c', 64), 'created_at' => $old, 'updated_at' => $old];
        }
        foreach (array_chunk($bulk, 500) as $chunk) {
            DB::table('inventory_idempotency_keys')->insert($chunk);
        }
        DB::table('inventory_idempotency_keys')->insert([
            'user_id' => $admin->id, 'key' => 'unused', 'request_hash' => str_repeat('d', 64),
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);

        $this->artisan('inventory:prune-idempotency-keys')->assertSuccessful();

        $this->assertSame(0, InventoryIdempotencyKey::query()->where('key', 'old-key')->count());
        $this->assertSame(1, InventoryIdempotencyKey::query()->where('key', 'fresh-key')->count());
        $this->assertSame(0, InventoryIdempotencyKey::query()->where('key', 'like', 'bulk-%')->count());
        $this->assertSame(1, InventoryIdempotencyKey::query()->where('key', 'unused')->count());
    }

    // ---------- §26: adjustment validation boundaries ----------

    public function test_adjustment_quantity_boundaries(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = $this->freshBranch();
        $variant = ProductVariant::factory()->create();

        $post = fn (mixed $qty) => $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'increase', 'quantity' => $qty]],
        ]);

        $post(0)->assertUnprocessable();
        $post(0.00001)->assertUnprocessable();
        $post(99999999.9999)->assertCreated();
        $post(100000000)->assertUnprocessable();
    }

    public function test_adjustment_rejects_too_many_items_and_foreign_location(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = $this->freshBranch();
        $other = $this->freshBranch();
        $variant = ProductVariant::factory()->create();

        $items = [];
        for ($i = 0; $i < 101; $i++) {
            $items[] = ['product_variant_id' => $variant->id, 'adjustment_type' => 'increase', 'quantity' => 1];
        }
        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id, 'items' => $items,
        ])->assertUnprocessable();

        $foreignLocation = \App\Models\Location::factory()->create(['branch_id' => $other->id]);
        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'location_id' => $foreignLocation->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'increase', 'quantity' => 1]],
        ])->assertUnprocessable();
    }

    public function test_transfer_rejects_same_branch(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = $this->freshBranch();
        $variant = ProductVariant::factory()->create();

        $this->requestWithToken($token, 'POST', '/api/v1/admin/transfers', [
            'from_branch_id' => $branch->id,
            'to_branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 1]],
        ])->assertUnprocessable();
    }

    // ---------- §27: inactive entities ----------

    public function test_inactive_variant_allows_decrease_but_blocks_increase(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = $this->freshBranch();
        $variant = ProductVariant::factory()->create(['is_active' => true]);
        $this->seedStock($token, $branch, $variant, 10);
        $variant->update(['is_active' => false]);

        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'increase', 'quantity' => 1]],
        ])->assertUnprocessable();

        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'decrease', 'quantity' => 1]],
        ])->assertCreated();

        $this->assertSame('9.0000', $this->level($variant->id, $branch->id)->on_hand);
    }

    public function test_inactive_location_blocks_any_movement(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = $this->freshBranch();
        $variant = ProductVariant::factory()->create();
        $location = \App\Models\Location::factory()->create(['branch_id' => $branch->id, 'is_active' => false]);

        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'location_id' => $location->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'increase', 'quantity' => 1]],
        ])->assertUnprocessable();
    }

    public function test_inactive_branch_blocks_any_movement(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = $this->freshBranch();
        $variant = ProductVariant::factory()->create();
        $this->seedStock($token, $branch, $variant, 10);
        $branch->update(['is_active' => false]);

        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'decrease', 'quantity' => 1]],
        ])->assertUnprocessable();

        $this->assertSame('10.0000', $this->level($variant->id, $branch->id)->on_hand);
    }

    public function test_transfer_to_stopped_destination_is_rejected_at_each_stage(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $from = $this->freshBranch();
        $to = $this->freshBranch();
        $variant = ProductVariant::factory()->create();
        $this->seedStock($token, $from, $variant, 20);

        $transfer = $this->requestWithToken($token, 'POST', '/api/v1/admin/transfers', [
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 4]],
        ])->assertCreated()->json('data');

        $to->update(['is_active' => false]);
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/approve", [
            'expected_version' => $transfer['version'],
        ])->assertUnprocessable();
        $this->assertSame('0.0000', $this->level($variant->id, $from->id)->reserved);

        $to->update(['is_active' => true]);
        $approved = $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/approve", [
            'expected_version' => $transfer['version'],
        ])->assertOk()->json('data');

        $to->update(['is_active' => false]);
        $itemId = $transfer['items'][0]['id'];
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/ship", [
            'expected_version' => $approved['version'],
            'items' => [['id' => $itemId, 'shipped_qty' => 4]],
        ])->assertUnprocessable();
        $this->assertSame('4.0000', $this->level($variant->id, $from->id)->reserved);
    }

    // ---------- §28: movements filters & timezone ----------

    public function test_movements_timezone_filter_uses_business_day(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = $this->freshBranch();
        $variant = ProductVariant::factory()->create();

        // 23:30 Cairo on Jan 5 (21:30 UTC): belongs to Cairo-day 05, not 06.
        $this->travelTo(Carbon::create(2026, 1, 5, 23, 30, 0, 'Africa/Cairo'));
        $this->seedStock($token, $branch, $variant, 10);
        $movement = StockMovement::query()->latest('id')->firstOrFail();
        $this->travelBack();

        $inDay = $this->requestWithToken($token, 'GET', '/api/v1/admin/inventory/movements?from_date=2026-01-05&to_date=2026-01-05');
        $inDay->assertOk();
        $this->assertContains($movement->id, collect($inDay->json('data'))->pluck('id')->all());

        $nextDay = $this->requestWithToken($token, 'GET', '/api/v1/admin/inventory/movements?from_date=2026-01-06&to_date=2026-01-06');
        $nextDay->assertOk();
        $this->assertNotContains($movement->id, collect($nextDay->json('data'))->pluck('id')->all());
    }

    public function test_movements_rejects_bad_filters(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);

        $this->requestWithToken($token, 'GET', '/api/v1/admin/inventory/movements?from_date=2026-02-02&to_date=2026-01-01')
            ->assertUnprocessable();
        $this->requestWithToken($token, 'GET', '/api/v1/admin/inventory/movements?type=nope')
            ->assertUnprocessable();
    }

    public function test_movements_cursor_pagination_is_stable_under_inserts(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = $this->freshBranch();
        $variant = ProductVariant::factory()->create();

        for ($i = 0; $i < 12; $i++) {
            $this->seedStock($token, $branch, $variant, 1);
        }

        $page1 = $this->requestWithToken($token, 'GET', '/api/v1/admin/inventory/movements?per_page=10')
            ->assertOk()->json();
        $this->assertCount(10, $page1['data']);
        $this->assertNotNull($page1['meta']['next_cursor'] ?? $page1['next_cursor'] ?? null);

        // New rows arrive while paginating.
        $this->seedStock($token, $branch, $variant, 1);

        $cursor = $page1['meta']['next_cursor'] ?? $page1['next_cursor'];
        $page2 = $this->requestWithToken($token, 'GET', '/api/v1/admin/inventory/movements?per_page=10&cursor='.urlencode(is_array($cursor) ? $cursor['cursor'] ?? '' : $cursor))
            ->assertOk()->json();

        $ids = array_merge(
            collect($page1['data'])->pluck('id')->all(),
            collect($page2['data'])->pluck('id')->all(),
        );
        $this->assertSame(count($ids), count(array_unique($ids))); // no repeats
        $this->assertCount(12, $ids); // the original window is fully covered, no skips
        $newest = StockMovement::query()->latest('id')->firstOrFail()->id;
        $this->assertNotContains($newest, $ids); // rows added mid-pagination do not leak into later pages
    }
}
