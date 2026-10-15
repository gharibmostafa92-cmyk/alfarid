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
        return [
            'id' => $this->id,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'slug' => $this->slug,
            'image' => $this->getFirstMediaUrl('image') ?: null,
            'products_count' => $this->whenCounted('activeProducts'),
            'children' => $this->whenLoaded('children', fn () => self::collection($this->children)->resolve($request)),
        ];
    }
}
