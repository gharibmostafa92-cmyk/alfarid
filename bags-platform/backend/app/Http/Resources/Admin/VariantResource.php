<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\MediaResource;
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
            'product_id' => $this->product_id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'color_name_ar' => $this->color_name_ar,
            'color_name_en' => $this->color_name_en,
            'color_hex' => $this->color_hex,
            'size' => $this->size,
            'capacity' => $this->capacity,
            // سعر التكلفة لا يظهر إلا لمن يملك products.view_cost
            'cost_price' => $this->when($request->user()?->can('products.view_cost'), $this->cost_price),
            'retail_price' => $this->retail_price,
            'wholesale_price' => $this->wholesale_price,
            'reorder_level' => $this->reorder_level,
            'weight_kg' => $this->weight_kg,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'images' => MediaResource::collection($this->whenLoaded('media', fn () => $this->getMedia('images'))),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
