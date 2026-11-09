<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Inventory;

use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\Inventory\InventoryService;

/**
 * task.txt §3 (notable cases per area not covered elsewhere).
 */
class TaskTxtInventoryEdgeCasesTest extends TaskTxtInventoryCase
{
    private function move(Branch $branch, ProductVariant $variant, string $type, string $bucket, mixed $qty, int $userId): StockMovement
    {
        return app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id,
            'type' => $type, 'affected_bucket' => $bucket, 'quantity' => $qty, 'created_by' => $userId,
        ]);
    }

    // ---------- recordMovement semantics ----------

    public function test_movement_semantics_matrix(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();

        // sale with a positive quantity → 422.
        try {
            $this->move($branch, $variant, StockMovementType::Sale->value, StockBucket::OnHand->value, 1, $admin->id);
            $this->fail('positive sale must be rejected');
        } catch (BusinessException) {
            $this->addToAssertionCount(1);
        }

        // reserve against the on_hand bucket → 422.
        try {
            $this->move($branch, $variant, StockMovementType::Reserve->value, StockBucket::OnHand->value, -1, $admin->id);
            $this->fail('reserve on on_hand must be rejected');
        } catch (BusinessException) {
            $this->addToAssertionCount(1);
        }

        // Non-numeric and infinite quantities → 422.
        foreach (['abc', INF] as $bad) {
            try {
                $this->move($branch, $variant, 'adjustment_in', 'on_hand', $bad, $admin->id);
                $this->fail('quantity '.var_export($bad, true).' must be rejected');
            } catch (BusinessException) {
                $this->addToAssertionCount(1);
            }
        }

        // 0.00004 rounds to zero → 422; 0.00005 rounds to 0.0001 → accepted.
        try {
            $this->move($branch, $variant, 'adjustment_in', 'on_hand', 0.00004, $admin->id);
            $this->fail('sub-unit dust must be rejected');
        } catch (BusinessException $e) {
            $this->assertSame('كمية الحركة يجب ألا تساوي صفرًا.', $e->getMessage());
        }
        $dust = $this->move($branch, $variant, 'adjustment_in', 'on_hand', 0.00005, $admin->id);
        $this->assertSame('0.0001', $dust->quantity);
    }

    // ---------- damaged flows post the documented movements ----------

    public function test_restore_and_write_off_damaged_post_exact_movements(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();

        $this->seedStock($token, $branch, $variant, 10);
        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'mark_damaged', 'quantity' => 4]],
        ])->assertCreated();

        // restore_damaged: exactly one movement (damaged −q), available +q.
        $before = StockMovement::query()->count();
        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'restore_damaged', 'quantity' => 1]],
        ])->assertCreated();
        $this->assertSame($before + 1, StockMovement::query()->count());
        $level = $this->level($variant->id, $branch->id);
        $this->assertSame('3.0000', $level->damaged);
        $this->assertSame(7.0, $level->available);

        // write_off_damaged: exactly two movements, available unchanged.
        $before = StockMovement::query()->count();
        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'write_off_damaged', 'quantity' => 2]],
        ])->assertCreated();
        $this->assertSame($before + 2, StockMovement::query()->count());
        $level = $this->level($variant->id, $branch->id);
        $this->assertSame('1.0000', $level->damaged);
        $this->assertSame('8.0000', $level->on_hand);
        $this->assertSame(7.0, $level->available);
    }

    public function test_duplicate_variant_in_two_items_executes_sequentially(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();

        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [
                ['product_variant_id' => $variant->id, 'adjustment_type' => 'increase', 'quantity' => 3],
                ['product_variant_id' => $variant->id, 'adjustment_type' => 'increase', 'quantity' => 4],
            ],
        ])->assertCreated();

        $this->assertSame('7.0000', $this->level($variant->id, $branch->id)->on_hand);
        $this->assertSame(2, StockMovement::query()->count());
    }

    // ---------- transfer guards ----------

    private function transferFixture(string $token, Branch $from, Branch $to, ProductVariant $variant, float $qty): array
    {
        $this->seedStock($token, $from, $variant, 20);

        return $this->requestWithToken($token, 'POST', '/api/v1/admin/transfers', [
            'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => $qty]],
        ])->assertCreated()->json('data');
    }

    public function test_transfer_quantity_guards(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $from = Branch::factory()->create(['is_active' => true]);
        $to = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();

        // Approving a zero total is rejected (cancel instead).
        $t = $this->transferFixture($token, $from, $to, $variant, 4);
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$t['id']}/approve", [
            'expected_version' => $t['version'],
            'items' => [['id' => $t['items'][0]['id'], 'approved_qty' => 0]],
        ])->assertUnprocessable();

        // Approved more than requested is rejected.
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$t['id']}/approve", [
            'expected_version' => $t['version'],
            'items' => [['id' => $t['items'][0]['id'], 'approved_qty' => 9]],
        ])->assertUnprocessable();

        $approved = $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$t['id']}/approve", [
            'expected_version' => $t['version'],
        ])->assertOk()->json('data');

        // Shipping more than approved is rejected.
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$t['id']}/ship", [
            'expected_version' => $approved['version'],
            'items' => [['id' => $t['items'][0]['id'], 'shipped_qty' => 9]],
        ])->assertUnprocessable();

        $shipped = $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$t['id']}/ship", [
            'expected_version' => $approved['version'],
            'items' => [['id' => $t['items'][0]['id'], 'shipped_qty' => 4]],
        ])->assertOk()->json('data');

        // Receiving more than shipped is rejected.
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$t['id']}/receive", [
            'expected_version' => $shipped['version'],
            'items' => [['id' => $t['items'][0]['id'], 'received_qty' => 9]],
        ])->assertUnprocessable();
    }

    public function test_zero_shipment_releases_the_whole_reserve(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $from = Branch::factory()->create(['is_active' => true]);
        $to = Branch::factory()->create(['is_active' => true]);
        $v1 = ProductVariant::factory()->create();
        $v2 = ProductVariant::factory()->create();
        $this->seedStock($token, $from, $v1, 20);
        $this->seedStock($token, $from, $v2, 20);

        $t = $this->requestWithToken($token, 'POST', '/api/v1/admin/transfers', [
            'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'items' => [
                ['product_variant_id' => $v1->id, 'requested_qty' => 4],
                ['product_variant_id' => $v2->id, 'requested_qty' => 4],
            ],
        ])->assertCreated()->json('data');
        $approved = $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$t['id']}/approve", [
            'expected_version' => $t['version'],
        ])->assertOk()->json('data');

        // An all-zero shipment is rejected (cancel instead); a zero line next
        // to a positive line releases that line's reserve.
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$t['id']}/ship", [
            'expected_version' => $approved['version'],
            'items' => [
                ['id' => $t['items'][0]['id'], 'shipped_qty' => 0],
                ['id' => $t['items'][1]['id'], 'shipped_qty' => 0],
            ],
        ])->assertUnprocessable();

        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$t['id']}/ship", [
            'expected_version' => $approved['version'],
            'items' => [
                ['id' => $t['items'][0]['id'], 'shipped_qty' => 3],
                ['id' => $t['items'][1]['id'], 'shipped_qty' => 0],
            ],
        ])->assertOk();

        $this->assertSame('0.0000', $this->level($v1->id, $from->id)->reserved);
        $this->assertSame('0.0000', $this->level($v2->id, $from->id)->reserved);
        $this->assertSame('17.0000', $this->level($v1->id, $from->id)->on_hand);
        $this->assertSame('20.0000', $this->level($v2->id, $from->id)->on_hand);
    }

    public function test_transfer_update_bumps_version_and_replaces_items(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $from = Branch::factory()->create(['is_active' => true]);
        $to = Branch::factory()->create(['is_active' => true]);
        $other = Branch::factory()->create(['is_active' => true]);
        $v1 = ProductVariant::factory()->create();
        $v2 = ProductVariant::factory()->create();

        $t = $this->transferFixture($token, $from, $to, $v1, 4);

        // Non-draft transfer cannot be edited.
        $approved = $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$t['id']}/approve", [
            'expected_version' => $t['version'],
        ])->assertOk()->json('data');
        $this->requestWithToken($token, 'PUT', "/api/v1/admin/transfers/{$t['id']}", ['notes' => 'x'])->assertUnprocessable();

        // Same-branch edit is rejected.
        $t2 = $this->transferFixture($token, $from, $to, $v1, 2);
        $this->requestWithToken($token, 'PUT', "/api/v1/admin/transfers/{$t2['id']}", [
            'from_branch_id' => $from->id, 'to_branch_id' => $from->id,
        ])->assertUnprocessable();

        // Items are replaced wholesale and the version bumps even for items-only edits.
        $updated = $this->requestWithToken($token, 'PUT', "/api/v1/admin/transfers/{$t2['id']}", [
            'items' => [['product_variant_id' => $v2->id, 'requested_qty' => 3]],
        ])->assertOk()->json('data');
        $this->assertSame($t2['version'] + 1, $updated['version']);
        $this->assertCount(1, $updated['items']);
        $this->assertSame($v2->id, $updated['items'][0]['product_variant_id']);
    }

    // ---------- POS transfer guards ----------

    public function test_pos_request_transfer_guards(): void
    {
        $mine = Branch::factory()->create(['is_active' => true]);
        $from = Branch::factory()->create(['is_active' => true]);
        $active = ProductVariant::factory()->create(['is_active' => true]);
        $dead = ProductVariant::factory()->create(['is_active' => false]);
        [, $token] = $this->posCashierWithShift($mine, ['transfers.create']);

        // Requesting from your own branch is rejected.
        $this->posWithToken($token)->postJson('/api/v1/pos/transfer-requests', [
            'from_branch_id' => $mine->id,
            'items' => [['product_variant_id' => $active->id, 'requested_qty' => 1]],
        ], ['Idempotency-Key' => 'edge-own-1'])->assertUnprocessable();

        // Inactive variant is rejected.
        $this->posWithToken($token)->postJson('/api/v1/pos/transfer-requests', [
            'from_branch_id' => $from->id,
            'items' => [['product_variant_id' => $dead->id, 'requested_qty' => 1]],
        ], ['Idempotency-Key' => 'edge-dead-1'])->assertUnprocessable();
    }

    // ---------- stocktake guards ----------

    public function test_stocktake_item_limit_and_scope_rules(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = Branch::factory()->create(['is_active' => true]);

        // 501 items exceed the 500 cap.
        $variants = ProductVariant::factory()->count(501)->create(['is_active' => true]);
        $items = $variants->map(fn ($v) => ['product_variant_id' => $v->id, 'counted_qty' => 1])->all();
        $this->requestWithToken($token, 'POST', '/api/v1/admin/stocktakes', [
            'branch_id' => $branch->id, 'items' => $items,
        ])->assertUnprocessable();

        // A branch with located stock requires a location-scoped stocktake.
        $located = ProductVariant::factory()->create();
        $loc = Location::factory()->create(['branch_id' => $branch->id, 'code' => 'EDGE-LOC']);
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $located->id, 'branch_id' => $branch->id, 'location_id' => $loc->id,
            'type' => 'adjustment_in', 'affected_bucket' => 'on_hand', 'quantity' => 3, 'created_by' => $admin->id,
        ]);
        $this->requestWithToken($token, 'POST', '/api/v1/admin/stocktakes', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $located->id, 'counted_qty' => 3]],
        ])->assertUnprocessable();
    }

    public function test_stocktake_status_transitions_and_delete_guards(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $this->seedStock($token, $branch, $variant, 5);

        $mk = fn () => $this->requestWithToken($token, 'POST', '/api/v1/admin/stocktakes', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'counted_qty' => 5]],
        ])->assertCreated()->json('data');

        // draft → in_progress is allowed; back to draft is not.
        $t = $mk();
        $this->requestWithToken($token, 'PUT', "/api/v1/admin/stocktakes/{$t['id']}", ['status' => 'in_progress'])->assertOk();
        $this->requestWithToken($token, 'PUT', "/api/v1/admin/stocktakes/{$t['id']}", ['status' => 'draft'])->assertUnprocessable();

        // Approved sessions cannot be edited or deleted.
        $this->requestWithToken($token, 'POST', "/api/v1/admin/stocktakes/{$t['id']}/approve", [])->assertOk();
        $this->requestWithToken($token, 'PUT', "/api/v1/admin/stocktakes/{$t['id']}", ['notes' => 'x'])->assertUnprocessable();
        $this->requestWithToken($token, 'DELETE', "/api/v1/admin/stocktakes/{$t['id']}")->assertUnprocessable();

        // POS scan with a dust quantity is rejected.
        [, $scanToken] = $this->posCashierWithShift($branch, ['stocktakes.update']);
        $t2 = $mk();
        $barcode = '6220000099999';
        $variant->update(['barcode' => $barcode]);
        $this->posWithToken($scanToken)->postJson("/api/v1/pos/stocktakes/{$t2['id']}/scan", [
            'barcode' => $barcode, 'qty' => 0.00001,
        ])->assertUnprocessable();
    }
}
