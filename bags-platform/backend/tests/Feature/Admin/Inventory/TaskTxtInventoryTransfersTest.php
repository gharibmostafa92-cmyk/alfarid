<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Inventory;

use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
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

        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/approve", [
            'expected_version' => $transfer['version'] + 99,
        ])->assertConflict()
            ->assertJsonPath('errors.current_version', $transfer['version']);

        $this->assertSame('0.0000', $this->level($variant->id, $from->id)->reserved);
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

        // Cancelled again → 422.
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$draft['id']}/cancel", [])->assertUnprocessable();

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
}
