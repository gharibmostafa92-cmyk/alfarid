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

        return [
            'id' => $this->id,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'slug' => $this->slug,
            'image' => $this->getFirstMediaUrl('image', 'thumb') ?: null,
            'direct_products_count' => $this->whenCounted('activeProducts'),
            'products_count' => (int) $this->active_products_count + array_sum(array_column($children, 'products_count')),
            'children' => $children,
        ];
    }
}
