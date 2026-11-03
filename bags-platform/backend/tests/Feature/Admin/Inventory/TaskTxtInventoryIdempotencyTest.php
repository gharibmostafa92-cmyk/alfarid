<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Inventory;

use App\Models\Branch;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\StockTransfer;
use App\Events\TransferPendingApproval;
use Illuminate\Support\Facades\Event;

/**
 * task.txt §5 (adjustment idempotency)، §6 (transfer/stocktake/POS idempotency).
 */
class TaskTxtInventoryIdempotencyTest extends TaskTxtInventoryCase
{
    private function adjustmentPayload(Branch $branch, ProductVariant $a, ProductVariant $b, mixed $qtyA, mixed $qtyB, bool $reversed = false): array
    {
        $items = [
            ['product_variant_id' => $a->id, 'adjustment_type' => 'increase', 'quantity' => $qtyA],
            ['product_variant_id' => $b->id, 'adjustment_type' => 'increase', 'quantity' => $qtyB],
        ];

        return ['branch_id' => $branch->id, 'items' => $reversed ? array_reverse($items) : $items];
    }

    // ---------- §5a: same key + same data → same movements, no duplicates ----------

    public function test_adjustment_replay_returns_same_movements(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = Branch::factory()->create(['is_active' => true]);
        [$a, $b] = [ProductVariant::factory()->create(), ProductVariant::factory()->create()];
        $key = 'adj-replay-1';

        $first = $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments',
            $this->adjustmentPayload($branch, $a, $b, 5, 2), ['Idempotency-Key' => $key])->assertCreated()->json('data');
        $count = StockMovement::query()->count();

        $second = $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments',
            $this->adjustmentPayload($branch, $a, $b, 5, 2), ['Idempotency-Key' => $key])->assertCreated()->json('data');

        $this->assertSame(
            collect($first)->pluck('id')->sort()->values()->all(),
            collect($second)->pluck('id')->sort()->values()->all()
        );
        $this->assertSame($count, StockMovement::query()->count());
    }

    // ---------- §5b: cosmetic differences share the fingerprint ----------

    public function test_adjustment_replay_ignores_cosmetic_payload_differences(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = Branch::factory()->create(['is_active' => true]);
        [$a, $b] = [ProductVariant::factory()->create(), ProductVariant::factory()->create()];
        $key = 'adj-replay-2';

        $first = $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments',
            $this->adjustmentPayload($branch, $a, $b, 5, 2), ['Idempotency-Key' => $key])->assertCreated()->json('data');
        $count = StockMovement::query()->count();

        // "5" instead of 5.0, reversed item order: same fingerprint.
        $second = $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments',
            $this->adjustmentPayload($branch, $a, $b, '5', 2.0, reversed: true), ['Idempotency-Key' => $key])
            ->assertCreated()->json('data');

        $this->assertSame(
            collect($first)->pluck('id')->sort()->values()->all(),
            collect($second)->pluck('id')->sort()->values()->all()
        );
        $this->assertSame($count, StockMovement::query()->count());
    }

    // ---------- §5c: same key + different data → 409 ----------

    public function test_adjustment_key_reuse_with_different_data_is_conflict(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = Branch::factory()->create(['is_active' => true]);
        [$a, $b] = [ProductVariant::factory()->create(), ProductVariant::factory()->create()];
        $key = 'adj-replay-3';

        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments',
            $this->adjustmentPayload($branch, $a, $b, 5, 2), ['Idempotency-Key' => $key])->assertCreated();
        $count = StockMovement::query()->count();

        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments',
            $this->adjustmentPayload($branch, $a, $b, 6, 2), ['Idempotency-Key' => $key])
            ->assertConflict()
            ->assertJsonPath('message', 'مفتاح عدم التكرار هذا مستخدم مسبقًا مع بيانات مختلفة.');

        $this->assertSame($count, StockMovement::query()->count());
    }

    // ---------- §6: transfer idempotency ----------

    private function transferPayload(Branch $from, Branch $to, ProductVariant $variant, float $qty): array
    {
        return [
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => $qty]],
        ];
    }

    public function test_transfer_idempotency_and_per_user_key_scope(): void
    {
        Event::fake([TransferPendingApproval::class]);
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $from = Branch::factory()->create(['is_active' => true]);
        $to = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $key = 'transfer-key-1';

        $first = $this->requestWithToken($token, 'POST', '/api/v1/admin/transfers',
            $this->transferPayload($from, $to, $variant, 4), ['Idempotency-Key' => $key])
            ->assertCreated()->json('data');

        // Same key + same data → same record, event fired once.
        $replay = $this->requestWithToken($token, 'POST', '/api/v1/admin/transfers',
            $this->transferPayload($from, $to, $variant, 4), ['Idempotency-Key' => $key])
            ->assertCreated()->json('data');
        $this->assertSame($first['id'], $replay['id']);
        $this->assertSame(1, StockTransfer::query()->count());
        Event::assertDispatchedTimes(TransferPendingApproval::class, 1);

        // Same key + different data → 409.
        $this->requestWithToken($token, 'POST', '/api/v1/admin/transfers',
            $this->transferPayload($from, $to, $variant, 9), ['Idempotency-Key' => $key])
            ->assertConflict()
            ->assertJsonPath('message', 'مفتاح عدم التكرار هذا مستخدم مسبقًا مع بيانات مختلفة.');
        $this->assertSame(1, StockTransfer::query()->count());

        // Same key from another user → new record (key is scoped per user).
        $other = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $otherToken = $this->tokenFor($other);
        $second = $this->requestWithToken($otherToken, 'POST', '/api/v1/admin/transfers',
            $this->transferPayload($from, $to, $variant, 4), ['Idempotency-Key' => $key])
            ->assertCreated()->json('data');
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame(2, StockTransfer::query()->count());
    }

    // ---------- §6: stocktake idempotency ----------

    public function test_stocktake_idempotency(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $key = 'stocktake-key-1';

        $payload = ['branch_id' => $branch->id, 'items' => [['product_variant_id' => $variant->id, 'counted_qty' => 3]]];
        $first = $this->requestWithToken($token, 'POST', '/api/v1/admin/stocktakes', $payload, ['Idempotency-Key' => $key])
            ->assertCreated()->json('data');

        $replay = $this->requestWithToken($token, 'POST', '/api/v1/admin/stocktakes', $payload, ['Idempotency-Key' => $key])
            ->assertCreated()->json('data');
        $this->assertSame($first['id'], $replay['id']);
        $this->assertSame(1, Stocktake::query()->count());

        $changed = ['branch_id' => $branch->id, 'items' => [['product_variant_id' => $variant->id, 'counted_qty' => 5]]];
        $this->requestWithToken($token, 'POST', '/api/v1/admin/stocktakes', $changed, ['Idempotency-Key' => $key])
            ->assertConflict();
        $this->assertSame(1, Stocktake::query()->count());

        $other = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $otherToken = $this->tokenFor($other);
        $second = $this->requestWithToken($otherToken, 'POST', '/api/v1/admin/stocktakes', $payload, ['Idempotency-Key' => $key])
            ->assertCreated()->json('data');
        $this->assertNotSame($first['id'], $second['id']);
    }

    // ---------- §6: POS transfer-request idempotency ----------

    public function test_pos_transfer_request_idempotency(): void
    {
        $mine = Branch::factory()->create(['is_active' => true]);
        $from = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create(['is_active' => true]);
        [, $token] = $this->posCashierWithShift($mine, ['transfers.create']);

        $payload = ['from_branch_id' => $from->id, 'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 2]]];
        $key = 'pos-transfer-key-1';

        $first = $this->posWithToken($token)->postJson('/api/v1/pos/transfer-requests', $payload, ['Idempotency-Key' => $key])
            ->assertCreated()->json();

        $replay = $this->posWithToken($token)->postJson('/api/v1/pos/transfer-requests', $payload, ['Idempotency-Key' => $key])
            ->assertCreated()->json();
        $this->assertSame($first['id'], $replay['id']);
        $this->assertSame(1, StockTransfer::query()->count());

        $changed = ['from_branch_id' => $from->id, 'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 7]]];
        $this->posWithToken($token)->postJson('/api/v1/pos/transfer-requests', $changed, ['Idempotency-Key' => $key])
            ->assertConflict();
        $this->assertSame(1, StockTransfer::query()->count());
    }
}
