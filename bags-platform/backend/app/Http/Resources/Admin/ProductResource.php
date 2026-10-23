<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\MediaResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
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
            'code' => $this->code,
            'name_ar' => $this->name_ar, 'name_en' => $this->name_en, 'slug' => $this->slug,
            'description_ar' => $this->description_ar, 'description_en' => $this->description_en,
            'material_ar' => $this->material_ar, 'material_en' => $this->material_en,
            'dimensions' => ['length_cm' => $this->length_cm, 'width_cm' => $this->width_cm, 'height_cm' => $this->height_cm],
            'weight_kg' => $this->weight_kg,
            'warranty_months' => $this->warranty_months, 'warranty_details' => $this->warranty_details,
            'country_of_origin' => $this->country_of_origin,
            'status' => $this->status->value,
            'sales_channel' => $this->sales_channel->value,
            'is_featured' => $this->is_featured,
            'published_at' => $this->published_at,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'brand' => null,
            'supplier_id' => null,
            'main_image' => $this->getFirstMediaUrl('images', 'thumb') ?: null,
            'images' => MediaResource::collection($this->whenLoaded('media', fn () => $this->getMedia('images'))),
            'video' => $this->whenLoaded('media', fn () => ($v = $this->getFirstMedia('video')) ? new MediaResource($v) : null),
            'variants_count' => $this->whenCounted('variants'),
            'min_retail_price' => $this->when(isset($this->min_retail_price), $this->min_retail_price),
            'max_retail_price' => $this->when(isset($this->max_retail_price), $this->max_retail_price),
            'variants' => VariantResource::collection($this->whenLoaded('variants')),
            'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
        ];
    }
}
