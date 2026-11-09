<?php

declare(strict_types=1);

namespace App\Http\Resources\Store\Catalog;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public storefront brand resource representation with optimized thumbnail fallback.
 */
class StoreBrandResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Conversions generate asynchronously; fall back to the original image until thumb exists.
        $logo = $this->getFirstMedia('logo');

        return [
            'id' => $this->id,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'slug' => $this->slug,
            'logo' => $logo && $logo->hasGeneratedConversion('thumb') ? $logo->getUrl('thumb') : ($logo?->getUrl() ?: null),
        ];
    }
}
