<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Administrative stock level resource representation detailing on-hand, reserved, and low-stock indicators.
 */
class StockLevelResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_variant_id' => $this->product_variant_id,
            'variant' => $this->whenLoaded('variant', fn () => [
                'id' => $this->variant->id,
                'sku' => $this->variant->sku,
                'barcode' => $this->variant->barcode,
                'product_id' => $this->variant->product_id,
                'product_name_ar' => $this->variant->relationLoaded('product') ? $this->variant->product?->name_ar : null,
                'product_name_en' => $this->variant->relationLoaded('product') ? $this->variant->product?->name_en : null,
            ]),
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ]),
            'location' => $this->whenLoaded('location', fn () => $this->location ? [
                'id' => $this->location->id,
                'name' => $this->location->name,
                'code' => $this->location->code,
            ] : null),
            'on_hand' => $this->on_hand,
            'reserved' => $this->reserved,
            'damaged' => $this->damaged,
            'in_transit' => $this->in_transit,
            'available' => number_format($this->available, 4, '.', ''),
            // Effective reorder threshold: row-level threshold if set (> 0), falling back to variant-level threshold.
            'reorder_level' => $this->effectiveReorderLevel(),
            // Row-level low stock indicator: rows without an effective threshold (> 0) are not marked as low.
            // For branch-aggregated low stock filtering, refer to StockLevel::whereLowStock.
            'is_low_stock' => $this->effectiveReorderLevel() > 0 && $this->available <= $this->effectiveReorderLevel(),
            'updated_at' => $this->updated_at,
        ];
    }

    /** Resolve effective reorder threshold (row-specific override or variant default). */
    private function effectiveReorderLevel(): float
    {
        if ((float) $this->reorder_level > 0) {
            return (float) $this->reorder_level;
        }

        return $this->relationLoaded('variant') ? (float) ($this->variant?->reorder_level ?? 0) : 0.0;
    }
}
