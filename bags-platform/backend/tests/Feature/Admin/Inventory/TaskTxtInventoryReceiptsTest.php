<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Inventory;

use App\Models\Branch;
use App\Models\ProductVariant;
use App\Models\ProductionReceipt;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Auth;

/**
 * task.txt §18 (production receipt + weighted average cost)، §26 (receipt validation).
 */
class TaskTxtInventoryReceiptsTest extends TaskTxtInventoryCase
{
    private function receiptPayload(Branch $branch, string $batch, array $items): array
    {
        return ['branch_id' => $branch->id, 'batch_number' => $batch, 'items' => $items];
    }

    private function item(ProductVariant $variant, int $qty, string $cost): array
    {
        return ['product_variant_id' => $variant->id, 'quantity' => $qty, 'unit_cost' => $cost];
    }

    // ---------- §18a: weighted average cost ----------

    public function test_weighted_average_cost_after_receipt(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create(['cost_price' => '5.0000', 'is_active' => true]);

        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'increase', 'quantity' => 10]],
        ])->assertCreated();

        $this->requestWithToken($token, 'POST', '/api/v1/admin/production-receipts',
            $this->receiptPayload($branch, 'BATCH-WAC-1', [$this->item($variant, 5, '8.0000')])
        )->assertCreated()
            ->assertJsonPath('message', 'تم استلام الإنتاج وإضافة الكميات للمخزون.');

        $variant->refresh();
        // (10×5 + 5×8) / 15 = 6.
        $this->assertSame('6.0000', $variant->cost_price);
        $this->assertSame('8.0000', $variant->last_cost_price);
        $this->assertSame('15.0000', $this->level($variant->id, $branch->id)->on_hand);

        $move = StockMovement::query()
            ->where('product_variant_id', $variant->id)
            ->where('reference_type', 'production_receipt')
            ->first();
        $this->assertNotNull($move);
        $this->assertSame('5.0000', $move->quantity);
    }

    // ---------- §18b: half-up rounding at the 4th decimal ----------

    public function test_average_cost_rounds_half_up(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create(['cost_price' => '1.0000', 'is_active' => true]);

        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'increase', 'quantity' => 2]],
        ])->assertCreated();

        $this->requestWithToken($token, 'POST', '/api/v1/admin/production-receipts',
            $this->receiptPayload($branch, 'BATCH-WAC-2', [$this->item($variant, 1, '0.0001')])
        )->assertCreated();

        // (2×1 + 1×0.0001) / 3 = 0.6667 (half-up).
        $this->assertSame('0.6667', $variant->fresh()->cost_price);
    }

    // ---------- §18c: case-insensitive batch uniqueness ----------

    public function test_duplicate_batch_number_case_insensitive(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create(['is_active' => true]);

        $this->requestWithToken($token, 'POST', '/api/v1/admin/production-receipts',
            $this->receiptPayload($branch, 'ab-1', [$this->item($variant, 1, '1.0000')])
        )->assertCreated();

        $this->requestWithToken($token, 'POST', '/api/v1/admin/production-receipts',
            $this->receiptPayload($branch, 'AB-1', [$this->item($variant, 1, '1.0000')])
        )->assertConflict()
            ->assertJsonPath('message', 'رقم دفعة الإنتاج مسجل بالفعل؛ راجع سجل الاستلام.');
    }

    // ---------- §18d: receipt idempotency ----------

    public function test_receipt_idempotency_and_conflict(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create(['is_active' => true]);
        $key = 'receipt-key-1';

        $payload = $this->receiptPayload($branch, 'BATCH-IDEM-1', [$this->item($variant, 2, '3.0000')]);
        $first = $this->requestWithToken($token, 'POST', '/api/v1/admin/production-receipts', $payload, ['Idempotency-Key' => $key])
            ->assertCreated()->json();
        $moves = StockMovement::query()->count();

        $replay = $this->requestWithToken($token, 'POST', '/api/v1/admin/production-receipts', $payload, ['Idempotency-Key' => $key])
            ->assertCreated()->json();
        $this->assertSame($first['data']['id'] ?? $first['id'], $replay['data']['id'] ?? $replay['id']);
        $this->assertSame($moves, StockMovement::query()->count());

        $changed = $this->receiptPayload($branch, 'BATCH-IDEM-2', [$this->item($variant, 2, '3.0000')]);
        $this->requestWithToken($token, 'POST', '/api/v1/admin/production-receipts', $changed, ['Idempotency-Key' => $key])
            ->assertConflict()
            ->assertJsonPath('message', 'مفتاح الطلب مستخدم مع بيانات مختلفة.');
        $this->assertSame($moves, StockMovement::query()->count());
        $this->assertSame(1, ProductionReceipt::query()->count());
    }

    // ---------- §18e: missing key ----------

    public function test_receipt_requires_idempotency_key(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create(['is_active' => true]);

        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/v1/admin/production-receipts',
            $this->receiptPayload($branch, 'BATCH-NOKEY', [$this->item($variant, 1, '1.0000')])
        )->assertUnprocessable();
    }

    // ---------- §18f/g: inactive variant / branch ----------

    public function test_receipt_rejects_inactive_variant_and_branch(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create(['is_active' => false]);

        $this->requestWithToken($token, 'POST', '/api/v1/admin/production-receipts',
            $this->receiptPayload($branch, 'BATCH-INACTIVE-1', [$this->item($variant, 1, '1.0000')])
        )->assertUnprocessable();

        $variant->update(['is_active' => true]);
        $branch->update(['is_active' => false]);
        $this->requestWithToken($token, 'POST', '/api/v1/admin/production-receipts',
            $this->receiptPayload($branch, 'BATCH-INACTIVE-2', [$this->item($variant, 1, '1.0000')])
        )->assertUnprocessable();
    }

    // ---------- §26: receipt validation ----------

    public function test_receipt_item_validation(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $this->tokenFor($admin);
        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create(['is_active' => true]);

        // 51 items → 422.
        $items = [];
        for ($i = 0; $i < 51; $i++) {
            $items[] = $this->item(ProductVariant::factory()->create(['is_active' => true]), 1, '1.0000');
        }
        $this->requestWithToken($token, 'POST', '/api/v1/admin/production-receipts',
            $this->receiptPayload($branch, 'BATCH-V-51', $items)
        )->assertUnprocessable();

        // Fractional quantity → 422.
        $this->requestWithToken($token, 'POST', '/api/v1/admin/production-receipts', [
            'branch_id' => $branch->id, 'batch_number' => 'BATCH-V-FRAC',
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1.5, 'unit_cost' => '1.0000']],
        ])->assertUnprocessable();

        // Extra field in item → 422.
        $this->requestWithToken($token, 'POST', '/api/v1/admin/production-receipts', [
            'branch_id' => $branch->id, 'batch_number' => 'BATCH-V-EXTRA',
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1, 'unit_cost' => '1.0000', 'note' => 'x']],
        ])->assertUnprocessable();

        // Zero unit cost is accepted and lowers the average.
        $this->requestWithToken($token, 'POST', '/api/v1/admin/production-receipts',
            $this->receiptPayload($branch, 'BATCH-V-ZERO', [$this->item($variant, 2, '0.0000')])
        )->assertCreated();
    }
}
