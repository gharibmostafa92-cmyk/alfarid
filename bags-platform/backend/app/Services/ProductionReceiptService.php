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
                $items = array_map(function (array $item) use ($variants): array {
                    $variant = $variants->get($item['product_variant_id']);

                    return [...$item, 'label' => ($variant?->product?->name_ar ?? '').' / '.($variant?->sku ?? '')];
                }, $data['items']);
                $receipt = ProductionReceipt::create([
                    ...$data, 'items' => $items, 'created_by' => $userId, 'idempotency_key' => $key, 'request_hash' => $hash,
                ]);

                foreach (collect($data['items'])->sortBy('product_variant_id') as $item) {
                    $variant = $variants->get($item['product_variant_id']);
                    if (! $variant || ! $variant->is_active || ! $variant->product) {
                        throw new BusinessException('أحد أصناف الإنتاج غير متاح.');
                    }
                    $quantity = (int) $item['quantity'];
                    $unitCost = round((float) $item['unit_cost'], 4);
                    $onHand = max(0, (float) StockLevel::where('product_variant_id', $variant->id)->sum('on_hand'));
                    $average = (($onHand * (float) $variant->cost_price) + ($quantity * $unitCost)) / ($onHand + $quantity);
                    $variant->update(['cost_price' => round($average, 4), 'last_cost_price' => $unitCost]);
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
            if (ProductionReceipt::where('batch_number', $data['batch_number'])->exists()) {
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
