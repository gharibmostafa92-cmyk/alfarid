<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Concerns;

use App\Models\ProductVariant;
use Illuminate\Validation\Validator;

/**
 * Bulk variant existence verification using a single whereIn query instead of individual Rule::exists checks.
 * Avoids N+1 query overhead on 500+ line stocktakes or transfers.
 * Active status checks are omitted here so outbound liquidation of inactive items remains possible.
 */
trait BulkVariantExists
{
    protected function addBulkVariantExistsCheck(Validator $validator, bool $activeOnly = false): void
    {
        $validator->after(function (Validator $v) use ($activeOnly): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $items = $this->input('items', []);
            if (! is_array($items) || $items === []) {
                return;
            }

            $ids = collect($items)
                ->pluck('product_variant_id')
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            if ($ids->isEmpty()) {
                return;
            }

            // withTrashed: allow outbound operations (adjustment/count/transfer) on soft-deleted variants
            // to permit liquidating existing balances; inbound movements remain guarded in InventoryService.
            $query = ProductVariant::query()->whereIn('id', $ids);
            if ($activeOnly) {
                $query->where('is_active', true)->whereHas('product');
            } else {
                $query->withTrashed();
            }
            $found = $query->pluck('id')->map(fn ($id) => (int) $id)->all();
            $missing = $ids->diff($found);

            if ($missing->isEmpty()) {
                return;
            }

            foreach ($items as $i => $item) {
                if (isset($item['product_variant_id']) && $missing->contains((int) $item['product_variant_id'])) {
                    $v->errors()->add("items.{$i}.product_variant_id", 'المتغير المحدد غير موجود.');
                }
            }
        });
    }
}
