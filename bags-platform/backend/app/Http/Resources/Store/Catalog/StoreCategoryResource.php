<?php

declare(strict_types=1);

namespace App\Http\Resources\Store\Catalog;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public storefront category resource representation including recursive descendant product counts.
 */
class StoreCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $children = $this->relationLoaded('children') ? self::collection($this->children)->resolve($request) : [];
        // Conversions generate asynchronously; fall back to original until thumb exists.
        $image = $this->getFirstMedia('image');

        return [
            'id' => $this->id,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'slug' => $this->slug,
            'description_en' => $this->description_en,
            'description_ar' => $this->description_ar,
            'image' => $image && $image->hasGeneratedConversion('thumb') ? $image->getUrl('thumb') : ($image?->getUrl() ?: null),
            'direct_products_count' => $this->whenCounted('activeProducts'),
            'products_count' => (int) $this->active_products_count + array_sum(array_column($children, 'products_count')),
            'children' => $children,
        ];
    }
}
