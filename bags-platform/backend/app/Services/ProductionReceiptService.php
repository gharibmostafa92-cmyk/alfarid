<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\ProductionReceipt;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ProductionReceiptService
{
    public function __construct(private readonly InventoryService $inventory) {}

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
                    // H4: bcmath weighted average — float loses precision once
                    // on_hand * cost crosses 2^53, silently biasing landed cost.
                    // F12: lock this variant's stock_levels rows before summing so
                    // the average is computed on a frozen balance even if a
                    // concurrent movement path ever skips the variant lock.
                    $lockedLevelIds = StockLevel::where('product_variant_id', $variant->id)->orderBy('id')->lockForUpdate()->pluck('id');
                    $onHandStr = (string) StockLevel::whereIn('id', $lockedLevelIds)->sum('on_hand');
                    $onHandStr = bccomp($onHandStr, '0', 4) < 0 ? '0' : $onHandStr;
                    $costStr = (string) $variant->cost_price;
                    $unitStr = $unitCost;
                    $total = bcadd(bcmul($onHandStr, $costStr, 8), bcmul((string) $quantity, $unitStr, 8), 8);
                    $average = bcadd(bcdiv($total, bcadd($onHandStr, (string) $quantity, 4), 5), '0.00005', 4);
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
        }
    }

    private function replay(ProductionReceipt $receipt, string $hash): ProductionReceipt
    {
        if (! hash_equals($receipt->request_hash, $hash)) {
            throw new BusinessException('مفتاح الطلب مستخدم مع بيانات مختلفة.', [], 409);
        }

        return $receipt;
    }
}
