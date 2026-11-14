<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Inventory;

use App\Enums\Inventory\StockBucket;
use App\Enums\Inventory\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\Inventory\TransferService;

/**
 * task.txt §7 (full journey)، §8 (version guards)، §9 (stage replay)، §10 (cancel/delete).
 */
class TaskTxtInventoryTransfersTest extends TaskTxtInventoryCase
{
    private function makeTransfer(string $token, Branch $from, Branch $to, ProductVariant $variant, float $qty): array
    {
        return $this->requestWithToken($token, 'POST', '/api/v1/admin/transfers', [
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => $qty]],
        ])->assertCreated()->json('data');
    }

    private function movementsFor(ProductVariant $variant): array
    {
        return StockMovement::query()
            ->where('product_variant_id', $variant->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($m) => [$m->type->value, $m->affected_bucket->value, $m->quantity])
            ->all();
    }

    // ---------- §7: exact journey with partial approve/ship/receive ----------

    public function test_full_transfer_journey_with_partial_quantities(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $from = Branch::factory()->create(['is_active' => true]);
        $to = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $this->seedStock($token, $from, $variant, 10);

        $transfer = $this->makeTransfer($token, $from, $to, $variant, 6);
        $itemId = $transfer['items'][0]['id'];

        // Approve 5 of 6.
        $approved = $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/approve", [
            'expected_version' => $transfer['version'],
            'items' => [['id' => $itemId, 'approved_qty' => 5]],
        ])->assertOk()->json('data');
        $this->assertSame('5.0000', $this->level($variant->id, $from->id)->reserved);
        $this->assertContains([StockMovementType::Reserve->value, StockBucket::Reserved->value, '5.0000'], $this->movementsFor($variant));

        // Ship 4 of 5.
        $shipped = $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/ship", [
            'expected_version' => $approved['version'],
            'items' => [['id' => $itemId, 'shipped_qty' => 4]],
        ])->assertOk()->json('data');
        $levelFrom = $this->level($variant->id, $from->id);
        $this->assertSame('0.0000', $levelFrom->reserved);
        $this->assertSame('6.0000', $levelFrom->on_hand);
        $moves = $this->movementsFor($variant);
        $this->assertContains([StockMovementType::ReleaseReserve->value, StockBucket::Reserved->value, '-4.0000'], $moves);
        $this->assertContains([StockMovementType::TransferOut->value, StockBucket::OnHand->value, '-4.0000'], $moves);
        $this->assertContains([StockMovementType::ReleaseReserve->value, StockBucket::Reserved->value, '-1.0000'], $moves);
        $this->assertContains([StockMovementType::TransferInTransit->value, StockBucket::InTransit->value, '4.0000'], $moves);
        $this->assertSame('4.0000', $this->level($variant->id, $to->id)->in_transit);

        // Receive 3 of 4: shortage of 1.
        $received = $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/receive", [
            'expected_version' => $shipped['version'],
            'items' => [['id' => $itemId, 'received_qty' => 3]],
        ])->assertOk()->json('data');
        $this->assertSame('received', $received['status']);
        $this->assertNotNull($received['received_at']);
        $this->assertStringContainsString('عجز 1', (string) $received['notes']);

        $levelTo = $this->level($variant->id, $to->id);
        $this->assertSame('0.0000', $levelTo->in_transit);
        $this->assertSame('3.0000', $levelTo->on_hand);
        $moves = $this->movementsFor($variant);
        $this->assertContains([StockMovementType::TransferTransitRelease->value, StockBucket::InTransit->value, '-3.0000'], $moves);
        $this->assertContains([StockMovementType::TransferIn->value, StockBucket::OnHand->value, '3.0000'], $moves);
        $this->assertContains([StockMovementType::TransferShortage->value, StockBucket::InTransit->value, '-1.0000'], $moves);
    }

    // ---------- §8a: stale version ----------

    public function test_approve_with_stale_version_is_conflict_without_reserve(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $from = Branch::factory()->create(['is_active' => true]);
        $to = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $this->seedStock($token, $from, $variant, 10);

        $transfer = $this->makeTransfer($token, $from, $to, $variant, 6);

        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/approve", [])
            ->assertUnprocessable()->assertJsonValidationErrors('expected_version');

        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/approve", [
            'expected_version' => $transfer['version'] + 99,
        ])->assertConflict()
            ->assertJsonPath('errors.current_version', $transfer['version']);

        $this->assertSame('0.0000', $this->level($variant->id, $from->id)->reserved);
    }

    // ---------- §9b: approve/retry + cancel/retry idempotency ----------

    public function test_approve_retry_after_success_replays_without_side_effects(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $from = Branch::factory()->create(['is_active' => true]);
        $to = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $this->seedStock($token, $from, $variant, 10);

        $transfer = $this->makeTransfer($token, $from, $to, $variant, 6);
        $itemId = $transfer['items'][0]['id'];
        $payload = ['expected_version' => $transfer['version'], 'items' => [['id' => $itemId, 'approved_qty' => 5]]];

        $approved = $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/approve", $payload)
            ->assertOk()->json('data');

        // Retrying with the same quantities and the current version replays the stage.
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/approve", [
            'expected_version' => $approved['version'],
            'items' => [['id' => $itemId, 'approved_qty' => 5]],
        ])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame('5.0000', $this->level($variant->id, $from->id)->reserved);
        $this->assertSame(1, StockMovement::where('product_variant_id', $variant->id)->where('type', StockMovementType::Reserve->value)->count());

        // Retrying with different quantities is a conflict, not a silent success.
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/approve", [
            'expected_version' => $approved['version'],
            'items' => [['id' => $itemId, 'approved_qty' => 4]],
        ])->assertConflict();
    }

    public function test_cancel_retry_after_success_returns_entity(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $from = Branch::factory()->create(['is_active' => true]);
        $to = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $this->seedStock($token, $from, $variant, 10);

        $transfer = $this->makeTransfer($token, $from, $to, $variant, 6);
        $itemId = $transfer['items'][0]['id'];
        $approved = $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/approve", [
            'expected_version' => $transfer['version'],
            'items' => [['id' => $itemId, 'approved_qty' => 5]],
        ])->assertOk()->json('data');

        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        // Retrying a completed cancellation returns the entity instead of an error.
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('0.0000', $this->level($variant->id, $from->id)->reserved);
        $this->assertSame(1, StockMovement::where('product_variant_id', $variant->id)->where('type', StockMovementType::Reserve->value)->count());
        $this->assertSame(1, StockMovement::where('product_variant_id', $variant->id)->where('type', StockMovementType::ReleaseReserve->value)->count());
    }

    // ---------- M-2: shortage write-off needs its own permission ----------

    public function test_receive_with_shortage_requires_shortage_permission(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $adminToken = $this->tokenFor($admin);
        $receiver = $this->staffWithPermissions([
            'inventory.view', 'inventory.all_branches',
            'transfers.view', 'transfers.create', 'transfers.receive',
        ]);
        $receiverToken = $this->tokenFor($receiver);
        $from = Branch::factory()->create(['is_active' => true]);
        $to = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $this->seedStock($adminToken, $from, $variant, 10);

        $transfer = $this->makeTransfer($adminToken, $from, $to, $variant, 6);
        $itemId = $transfer['items'][0]['id'];
        $approved = $this->requestWithToken($adminToken, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/approve", [
            'expected_version' => $transfer['version'],
            'items' => [['id' => $itemId, 'approved_qty' => 6]],
        ])->assertOk()->json('data');
        $shipped = $this->requestWithToken($adminToken, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/ship", [
            'expected_version' => $approved['version'],
            'items' => [['id' => $itemId, 'shipped_qty' => 4]],
        ])->assertOk()->json('data');

        // Declaring a shortage without the shortage permission is forbidden and writes nothing.
        $this->requestWithToken($receiverToken, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/receive", [
            'expected_version' => $shipped['version'],
            'items' => [['id' => $itemId, 'received_qty' => 3]],
        ])->assertForbidden();
        $this->assertSame('4.0000', $this->level($variant->id, $to->id)->in_transit);
        $this->assertSame(0, StockMovement::where('product_variant_id', $variant->id)->where('type', StockMovementType::TransferShortage->value)->count());

        // A full receipt with no shortage still works without the extra permission.
        $this->requestWithToken($receiverToken, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/receive", [
            'expected_version' => $shipped['version'],
            'items' => [['id' => $itemId, 'received_qty' => 4]],
        ])->assertOk()->assertJsonPath('data.status', 'received');
        $this->assertSame('4.0000', $this->level($variant->id, $to->id)->on_hand);
    }

    public function test_receive_with_shortage_succeeds_with_shortage_permission(): void
    {
        $admin = $this->staffWithPermissions(array_merge(self::ALL_PERMISSIONS, ['transfers.approve_shortage']));
        $token = $this->tokenFor($admin);
        $from = Branch::factory()->create(['is_active' => true]);
        $to = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $this->seedStock($token, $from, $variant, 10);

        $transfer = $this->makeTransfer($token, $from, $to, $variant, 6);
        $itemId = $transfer['items'][0]['id'];
        $approved = $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/approve", [
            'expected_version' => $transfer['version'],
            'items' => [['id' => $itemId, 'approved_qty' => 6]],
        ])->assertOk()->json('data');
        $shipped = $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/ship", [
            'expected_version' => $approved['version'],
            'items' => [['id' => $itemId, 'shipped_qty' => 4]],
        ])->assertOk()->json('data');

        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/receive", [
            'expected_version' => $shipped['version'],
            'items' => [['id' => $itemId, 'received_qty' => 3]],
        ])->assertOk()->assertJsonPath('data.status', 'received');
        $this->assertSame('3.0000', $this->level($variant->id, $to->id)->on_hand);
        $this->assertSame(1, StockMovement::where('product_variant_id', $variant->id)->where('type', StockMovementType::TransferShortage->value)->count());
    }

    // ---------- L-6: destination branch or creator may edit/cancel a draft ----------

    public function test_destination_branch_user_can_update_and_cancel_draft(): void
    {
        $from = Branch::factory()->create(['is_active' => true]);
        $to = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $this->seedStock($this->tokenFor($admin), $from, $variant, 10);

        $destination = $this->staffWithPermissions([
            'inventory.view', 'transfers.view', 'transfers.create', 'transfers.update',
        ], $to->id);
        $destinationToken = $this->tokenFor($destination);

        // Destination user creates the draft (allowed) then edits and cancels it.
        $transfer = $this->requestWithToken($destinationToken, 'POST', '/api/v1/admin/transfers', [
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 4]],
        ])->assertCreated()->json('data');

        $this->requestWithToken($destinationToken, 'PATCH', "/api/v1/admin/transfers/{$transfer['id']}", [
            'notes' => 'destination edit',
        ])->assertOk()->assertJsonPath('data.notes', 'destination edit');

        $this->requestWithToken($destinationToken, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/cancel", [])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_unrelated_branch_user_cannot_touch_draft(): void
    {
        $from = Branch::factory()->create(['is_active' => true]);
        $to = Branch::factory()->create(['is_active' => true]);
        $other = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $this->seedStock($this->tokenFor($admin), $from, $variant, 10);

        $outsider = $this->staffWithPermissions([
            'inventory.view', 'transfers.view', 'transfers.update',
        ], $other->id);
        $outsiderToken = $this->tokenFor($outsider);

        $transfer = $this->makeTransfer($this->tokenFor($admin), $from, $to, $variant, 4);

        $this->requestWithToken($outsiderToken, 'PATCH', "/api/v1/admin/transfers/{$transfer['id']}", [
            'notes' => 'hijack',
        ])->assertForbidden();
        $this->requestWithToken($outsiderToken, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/cancel", [])
            ->assertForbidden();
        $this->assertSame('draft', $transfer['status']);
    }

    // ---------- §8c: updated_at without version (service level) ----------

    public function test_service_rejects_updated_at_without_version(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $from = Branch::factory()->create(['is_active' => true]);
        $to = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $service = app(TransferService::class);

        app(\App\Services\Inventory\InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id, 'branch_id' => $from->id,
            'type' => StockMovementType::AdjustmentIn, 'affected_bucket' => StockBucket::OnHand,
            'quantity' => 10, 'created_by' => $admin->id,
        ]);
        $transfer = $service->create([
            'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 4]],
        ], $admin->id);

        try {
            $service->approve($transfer, [], $admin->id, now()->toDateTimeString(), null);
            $this->fail('updated_at without version must be rejected');
        } catch (BusinessException $e) {
            $this->assertSame(409, $e->status);
        }
        $this->assertSame('0.0000', $this->level($variant->id, $from->id)->reserved);
    }

    // ---------- §8d: unknown item ids ----------

    public function test_approve_with_unknown_item_id(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $from = Branch::factory()->create(['is_active' => true]);
        $to = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $this->seedStock($token, $from, $variant, 10);

        $transfer = $this->makeTransfer($token, $from, $to, $variant, 6);

        // API layer: unknown id fails validation.
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/approve", [
            'expected_version' => $transfer['version'],
            'items' => [['id' => 999999, 'approved_qty' => 1]],
        ])->assertUnprocessable();

        // Service layer: unknown id is a 409 with unknown_item_ids.
        try {
            app(TransferService::class)->approve(
                \App\Models\StockTransfer::findOrFail($transfer['id']),
                [999999 => 1], $admin->id, null, $transfer['version']
            );
            $this->fail('unknown item id must be rejected');
        } catch (BusinessException $e) {
            $this->assertSame(409, $e->status);
            $this->assertArrayHasKey('unknown_item_ids', $e->errors);
        }
    }

    // ---------- §9: stage replay ----------

    public function test_replaying_completed_stage_with_same_quantities_is_idempotent(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $from = Branch::factory()->create(['is_active' => true]);
        $to = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $this->seedStock($token, $from, $variant, 10);

        $transfer = $this->makeTransfer($token, $from, $to, $variant, 4);
        $itemId = $transfer['items'][0]['id'];
        $approved = $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/approve", [
            'expected_version' => $transfer['version'],
            'items' => [['id' => $itemId, 'approved_qty' => 4]],
        ])->assertOk()->json('data');
        $shipped = $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/ship", [
            'expected_version' => $approved['version'],
            'items' => [['id' => $itemId, 'shipped_qty' => 4]],
        ])->assertOk()->json('data');

        $count = StockMovement::query()->count();
        // Same quantities → 200, no new movements.
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/ship", [
            'expected_version' => $shipped['version'],
            'items' => [['id' => $itemId, 'shipped_qty' => 4]],
        ])->assertOk();
        $this->assertSame($count, StockMovement::query()->count());

        // Different quantities → 409.
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/ship", [
            'expected_version' => $shipped['version'],
            'items' => [['id' => $itemId, 'shipped_qty' => 3]],
        ])->assertConflict()
            ->assertJsonPath('message', 'تم تنفيذ المرحلة بكميات مختلفة؛ أعد تحميل التحويل.');
        $this->assertSame($count, StockMovement::query()->count());

        // Same for receive.
        $received = $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/receive", [
            'expected_version' => $shipped['version'],
            'items' => [['id' => $itemId, 'received_qty' => 4]],
        ])->assertOk()->json('data');
        $count = StockMovement::query()->count();

        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/receive", [
            'expected_version' => $received['version'],
            'items' => [['id' => $itemId, 'received_qty' => 4]],
        ])->assertOk();
        $this->assertSame($count, StockMovement::query()->count());

        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/receive", [
            'expected_version' => $received['version'],
            'items' => [['id' => $itemId, 'received_qty' => 2]],
        ])->assertConflict();
        $this->assertSame($count, StockMovement::query()->count());
    }

    // ---------- §10: cancel & delete by status ----------

    public function test_cancel_releases_reserve_only_from_approved(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $from = Branch::factory()->create(['is_active' => true]);
        $to = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $this->seedStock($token, $from, $variant, 10);

        // Draft: cancels with no movements.
        $draft = $this->makeTransfer($token, $from, $to, $variant, 3);
        $movesBefore = StockMovement::query()->count();
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$draft['id']}/cancel", [])->assertOk();
        $this->assertSame($movesBefore, StockMovement::query()->count());

        // Cancelled again → idempotent replay of the entity.
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$draft['id']}/cancel", [])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame($movesBefore, StockMovement::query()->count());

        // Approved: cancel releases the reserve.
        $transfer = $this->makeTransfer($token, $from, $to, $variant, 5);
        $approved = $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/approve", [
            'expected_version' => $transfer['version'],
        ])->assertOk()->json('data');
        $this->assertSame('5.0000', $this->level($variant->id, $from->id)->reserved);

        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/cancel", [])->assertOk();
        $this->assertSame('0.0000', $this->level($variant->id, $from->id)->reserved);
        $this->assertContains(
            [StockMovementType::ReleaseReserve->value, StockBucket::Reserved->value, '-5.0000'],
            $this->movementsFor($variant)
        );

        // Shipped / received: cancel rejected, balances untouched.
        $t2 = $this->makeTransfer($token, $from, $to, $variant, 4);
        $itemId = $t2['items'][0]['id'];
        $a2 = $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$t2['id']}/approve", [
            'expected_version' => $t2['version'],
        ])->assertOk()->json('data');
        $s2 = $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$t2['id']}/ship", [
            'expected_version' => $a2['version'],
            'items' => [['id' => $itemId, 'shipped_qty' => 4]],
        ])->assertOk()->json('data');

        $onHand = $this->level($variant->id, $from->id)->on_hand;
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$t2['id']}/cancel", [])->assertUnprocessable();
        $this->assertSame($onHand, $this->level($variant->id, $from->id)->on_hand);

        $r2 = $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$t2['id']}/receive", [
            'expected_version' => $s2['version'],
            'items' => [['id' => $itemId, 'received_qty' => 4]],
        ])->assertOk()->json('data');
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$t2['id']}/cancel", [])->assertUnprocessable();
        $this->assertSame($onHand, $this->level($variant->id, $from->id)->on_hand);
    }

    public function test_delete_allowed_only_without_ledger_trace(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $from = Branch::factory()->create(['is_active' => true]);
        $to = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $this->seedStock($token, $from, $variant, 10);

        // Draft deletes cleanly.
        $draft = $this->makeTransfer($token, $from, $to, $variant, 3);
        $this->requestWithToken($token, 'DELETE', "/api/v1/admin/transfers/{$draft['id']}")->assertOk();

        // Cancelled draft (no movements) deletes cleanly.
        $draft2 = $this->makeTransfer($token, $from, $to, $variant, 3);
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$draft2['id']}/cancel", [])->assertOk();
        $this->requestWithToken($token, 'DELETE', "/api/v1/admin/transfers/{$draft2['id']}")->assertOk();

        // Approved then cancelled (has movements) cannot be deleted.
        $transfer = $this->makeTransfer($token, $from, $to, $variant, 5);
        $approved = $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/approve", [
            'expected_version' => $transfer['version'],
        ])->assertOk()->json('data');
        $this->requestWithToken($token, 'DELETE', "/api/v1/admin/transfers/{$transfer['id']}")->assertUnprocessable();
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/cancel", [])->assertOk();
        $this->requestWithToken($token, 'DELETE', "/api/v1/admin/transfers/{$transfer['id']}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'لا يمكن حذف تحويل له حركات مخزون مسجلة في الدفتر؛ إلغاؤه يحفظ أثر التدقيق.');
    }

    public function test_default_receiving_location_is_persisted_before_approval_and_duplicate_lines_are_rejected(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $from = Branch::factory()->create(['is_active' => true]);
        $to = Branch::factory()->create(['is_active' => true]);
        $receiving = Location::factory()->create([
            'branch_id' => $to->id,
            'type' => 'receiving',
        ]);
        $variant = ProductVariant::factory()->create();
        $this->seedStock($token, $from, $variant, 10);

        $response = $this->requestWithToken($token, 'POST', '/api/v1/admin/transfers', [
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'items' => [
                ['product_variant_id' => $variant->id, 'requested_qty' => 2],
                ['product_variant_id' => $variant->id, 'to_location_id' => $receiving->id, 'requested_qty' => 3],
            ],
        ]);

        $response->assertUnprocessable();
        $this->assertSame(0, \App\Models\StockTransfer::query()->count());
        $this->assertSame('0.0000', $this->level($variant->id, $from->id)->reserved);
    }

    public function test_receiving_legacy_branch_level_transit_rewrites_item_location_to_match_ledger(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $from = Branch::factory()->create(['is_active' => true]);
        $to = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $this->seedStock($token, $from, $variant, 10);

        $transfer = $this->makeTransfer($token, $from, $to, $variant, 4);
        $itemId = $transfer['items'][0]['id'];
        $approved = $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/approve", [
            'expected_version' => $transfer['version'],
        ])->assertOk()->json('data');
        $shipped = $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/ship", [
            'expected_version' => $approved['version'],
        ])->assertOk()->json('data');

        $receiving = Location::factory()->create(['branch_id' => $to->id, 'type' => 'receiving']);
        $received = $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/receive", [
            'expected_version' => $shipped['version'],
        ])->assertOk()->json('data');

        $this->assertSame($receiving->id, $received['items'][0]['received_location_id']);
        $this->assertSame('0.0000', $this->level($variant->id, $to->id)->in_transit);
        $this->assertDatabaseHas('stock_levels', [
            'branch_id' => $to->id,
            'location_id' => $receiving->id,
            'product_variant_id' => $variant->id,
            'on_hand' => '4.0000',
        ]);
    }

    public function test_canceling_approved_transfer_requires_approval_permission(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $adminToken = $this->tokenFor($admin);
        $from = Branch::factory()->create(['is_active' => true]);
        $to = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $this->seedStock($adminToken, $from, $variant, 10);
        $transfer = $this->makeTransfer($adminToken, $from, $to, $variant, 4);
        $this->requestWithToken($adminToken, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/approve", [
            'expected_version' => $transfer['version'],
        ])->assertOk();

        $updater = $this->staffWithPermissions(['transfers.update'], $from->id);
        $this->requestWithToken($this->tokenFor($updater), 'POST', "/api/v1/admin/transfers/{$transfer['id']}/cancel", [])
            ->assertForbidden();

        $approver = $this->staffWithPermissions(['transfers.approve'], $from->id);
        $this->requestWithToken($this->tokenFor($approver), 'POST', "/api/v1/admin/transfers/{$transfer['id']}/cancel", [])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_destination_creator_can_delete_cancelled_draft_without_ledger_history(): void
    {
        $from = Branch::factory()->create(['is_active' => true]);
        $to = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $creator = $this->staffWithPermissions(['transfers.create', 'transfers.update', 'transfers.delete'], $to->id);
        $token = $this->tokenFor($creator);
        $transfer = $this->requestWithToken($token, 'POST', '/api/v1/admin/transfers', [
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 1]],
        ])->assertCreated()->json('data');

        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/cancel", [])->assertOk();
        $this->requestWithToken($token, 'DELETE', "/api/v1/admin/transfers/{$transfer['id']}")->assertOk();
    }

    public function test_replaying_partial_approval_without_quantities_conflicts_with_default_full_request(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $from = Branch::factory()->create(['is_active' => true]);
        $to = Branch::factory()->create(['is_active' => true]);
        $one = ProductVariant::factory()->create();
        $two = ProductVariant::factory()->create();
        $this->seedStock($token, $from, $one, 10);
        $this->seedStock($token, $from, $two, 10);
        $transfer = $this->requestWithToken($token, 'POST', '/api/v1/admin/transfers', [
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'items' => [
                ['product_variant_id' => $one->id, 'requested_qty' => 5],
                ['product_variant_id' => $two->id, 'requested_qty' => 4],
            ],
        ])->assertCreated()->json('data');
        $firstId = $transfer['items'][0]['id'];
        $approved = $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/approve", [
            'expected_version' => $transfer['version'],
            'items' => [['id' => $firstId, 'approved_qty' => 3]],
        ])->assertOk()->json('data');

        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/approve", [
            'expected_version' => $approved['version'],
        ])->assertConflict();
    }

    public function test_update_transfer_request_reports_same_branch_on_the_changed_field(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $from = Branch::factory()->create(['is_active' => true]);
        $to = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $transfer = $this->makeTransfer($token, $from, $to, $variant, 1);

        $this->requestWithToken($token, 'PATCH', "/api/v1/admin/transfers/{$transfer['id']}", [
            'to_branch_id' => $from->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('to_branch_id');

        $this->requestWithToken($token, 'PATCH', "/api/v1/admin/transfers/{$transfer['id']}", [
            'from_branch_id' => $to->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('from_branch_id');
    }
}
