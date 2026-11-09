<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Services\Shared\IdempotencyFingerprint;

use App\Enums\Inventory\StockBucket;
use App\Enums\Inventory\StockMovementType;
use App\Exceptions\BusinessException;
use App\Exceptions\RetryableConflict;
use App\Models\Branch;
use App\Models\ProductionReceipt;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Handles finished goods manufacturing intake and inventory ledger posting.
 *
 * Recalculates weighted average cost (WAC) for manufactured variants across all branches
 * and records production receipt movements into branch on-hand inventory.
 */
class ProductionReceiptService
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * Intake a manufactured batch into branch inventory and update variant landed cost.
     *
     * @param  array{branch_id: int, batch_number: string, items: list<array{product_variant_id: int, quantity: int|float, unit_cost: string|float|int}>, location_id?: int|null, notes?: string|null}  $data  Intake payload and batch details.
     * @param  int  $userId  Authenticated staff user recording the receipt.
     * @param  string  $key  Idempotency key for request deduplication.
     * @return ProductionReceipt Created receipt model instance.
     *
     * @throws BusinessException If branch is inactive or variants are unavailable.
     * @throws RetryableConflict If concurrent inventory locks hit the timeout window.
     * @throws UniqueConstraintViolationException If batch number or idempotency key collides.
     */
    public function receive(array $data, int $userId, string $key): ProductionReceipt
    {
        app(BranchAccess::class)->assertBranch($userId, (int) $data['branch_id']);
        $hash = IdempotencyFingerprint::hash($data);
        $existing = ProductionReceipt::where('created_by', $userId)->where('idempotency_key', $key)->first();
        if ($existing) {
            return $this->replay($existing, $hash);
        }

        try {
            return DB::transaction(function () use ($data, $userId, $key, $hash): ProductionReceipt {
                DB::statement("SET LOCAL lock_timeout = '5s'");
                $branch = Branch::whereKey($data['branch_id'])->sharedLock()->firstOrFail();
                if (! $branch->is_active) {
                    throw new BusinessException('الفرع غير نشط.');
                }
                $variants = ProductVariant::whereIn('id', array_column($data['items'], 'product_variant_id'))
                    ->with('product:id,name_ar')->orderBy('id')->lockForUpdate()->get()->keyBy('id');

                $sortedItems = collect($data['items'])->sortBy('product_variant_id')->values()->all();
                $itemsWithLabels = [];

                foreach ($sortedItems as $item) {
                    $variant = $variants->get($item['product_variant_id']);
                    if (! $variant || ! $variant->is_active || ! $variant->product) {
                        throw new BusinessException('أحد أصناف الإنتاج غير متاح.');
                    }
                    $itemsWithLabels[] = [
                        ...$item,
                        'label' => $variant->product->name_ar.' / '.$variant->sku,
                    ];
                }

                $receipt = ProductionReceipt::create([
                    ...$data, 'items' => $itemsWithLabels, 'created_by' => $userId, 'idempotency_key' => $key, 'request_hash' => $hash,
                ]);

                foreach ($sortedItems as $item) {
                    $variant = $variants->get($item['product_variant_id']);
                    $quantity = (int) $item['quantity'];
                    $unitCost = bcadd((string) $item['unit_cost'], '0', 4);
                    // Use bcmath to prevent IEEE-754 precision loss and bias on high-value stock totals.
                    // Lock variant stock levels to freeze company-wide balances during weighted cost calculation.
                    $lockedLevelIds = StockLevel::where('product_variant_id', $variant->id)->orderBy('id')->lockForUpdate()->pluck('id');
                    // Include in-transit stock in company inventory to avoid skewing average cost during pending transfers.
                    $onHandStr = (string) StockLevel::whereIn('id', $lockedLevelIds)
                        ->sum(DB::raw('on_hand + in_transit'));
                    $average = InventoryService::calculateWeightedAverageCost($onHandStr, (string) $variant->cost_price, $quantity, $unitCost);
                    $variant->update(['cost_price' => $average, 'last_cost_price' => $unitCost]);
                    $this->inventory->recordMovement([
                        'product_variant_id' => $variant->id,
                        'branch_id' => (int) $data['branch_id'],
                        'location_id' => $data['location_id'] ?? null,
                        'type' => StockMovementType::ProductionReceipt,
                        'affected_bucket' => StockBucket::OnHand,
                        'quantity' => $quantity, 'unit_cost' => $unitCost,
                        'reference_type' => ProductionReceipt::class, 'reference_id' => $receipt->id,
                        'created_by' => $userId, 'notes' => $data['notes'] ?? null,
                    ]);
                }

                return $receipt;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = ProductionReceipt::where('created_by', $userId)->where('idempotency_key', $key)->first();
            if ($existing) {
                return $this->replay($existing, $hash);
            }
            if (ProductionReceipt::whereRaw('LOWER(batch_number) = ?', [mb_strtolower($data['batch_number'])])->exists()) {
                throw new BusinessException('رقم دفعة الإنتاج مسجل بالفعل؛ راجع سجل الاستلام.', [], 409);
            }
            throw $exception;
        } catch (QueryException $exception) {
            // Postgres 55P03 lock timeout indicates transient concurrency conflict rather than a fatal query failure.
            if (($exception->errorInfo[0] ?? null) === '55P03') {
                throw new RetryableConflict('المخزون مشغول بعملية أخرى الآن؛ أعد المحاولة بعد لحظات.', $exception);
            }
            throw $exception;
        }
    }

    /**
     * Return previously created receipt if request payload hash matches idempotency key.
     */
    private function replay(ProductionReceipt $receipt, string $hash): ProductionReceipt
    {
        if (! hash_equals($receipt->request_hash, $hash)) {
            throw new BusinessException('مفتاح الطلب مستخدم مع بيانات مختلفة.', [], 409);
        }

        return $receipt;
    }
}
