<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\MediaResource;
use App\Services\CostAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VariantResource extends JsonResource
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
            'lock_version' => (int) $this->lock_version,
            'product_id' => $this->product_id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'color' => ['name_ar' => $this->color_name_ar, 'name_en' => $this->color_name_en, 'hex' => $this->color_hex],
            'size' => $this->size,
            'capacity' => $this->capacity,
            'cost_price' => $this->when(CostAccess::canView($request->user()), $this->cost_price),
            'retail_price' => $this->retail_price,
            'compare_at_price' => $this->compare_at_price,
            'wholesale_price' => $this->wholesale_price,
            'reorder_level' => $this->reorder_level,
            'weight_kg' => $this->weight_kg ?? $this->product?->weight_kg,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'images' => MediaResource::collection($this->whenLoaded('media', fn () => $this->getMedia('images'))),
        ];
    }
}
