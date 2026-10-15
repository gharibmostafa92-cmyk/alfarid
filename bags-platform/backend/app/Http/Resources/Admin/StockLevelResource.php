<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'reorder_level' => $this->reorder_level,
            'is_low_stock' => $this->available <= (float) $this->reorder_level,
            'updated_at' => $this->updated_at,
        ];
    }
}
