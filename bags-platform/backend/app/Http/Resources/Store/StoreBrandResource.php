<?php

declare(strict_types=1);

namespace App\Http\Resources\Store;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreBrandResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // F6: conversions render on the queue — a thumb URL emitted before the
        // worker finishes is a broken CDN link. Fall back to the original.
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
