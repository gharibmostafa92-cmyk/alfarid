<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BrandResource extends JsonResource
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
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'slug' => $this->slug,
            'description_ar' => $this->description_ar,
            'description_en' => $this->description_en,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'logo_url' => $this->getFirstMediaUrl('logo') ?: null,
            'products_count' => $this->whenCounted('products'),
            // Historical total, including soft-deleted products.
            'all_products_count' => $this->whenCounted('all_products_count'),
            'created_at' => $this->created_at,
        ];
    }
}
