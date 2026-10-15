<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaResource extends JsonResource
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
            'collection' => $this->collection_name,
            'url' => $this->getUrl(),
            'thumb_url' => $this->hasGeneratedConversion('thumb') ? $this->getUrl('thumb') : null,
            'medium_url' => $this->hasGeneratedConversion('medium') ? $this->getUrl('medium') : null,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'order' => $this->order_column,
            'variant_id' => $this->model_type === ProductVariant::class ? $this->model_id : null,
        ];
    }
}
