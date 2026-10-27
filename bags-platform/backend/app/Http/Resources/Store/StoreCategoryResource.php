<?php

declare(strict_types=1);

namespace App\Http\Resources\Store;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $children = $this->relationLoaded('children') ? self::collection($this->children)->resolve($request) : [];
        // F6: conversions render on the queue — a thumb URL emitted before the
        // worker finishes is a broken CDN link. Fall back to the original.
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
