<?php

declare(strict_types=1);

namespace App\Http\Resources\Store;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class StoreBannerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'image' => str_starts_with($this->image_path, 'https://') || str_starts_with($this->image_path, 'http://')
                ? $this->image_path : Storage::disk('public')->url($this->image_path),
            'link_type' => $this->link_type,
            'link_value' => $this->link_value,
            'position' => $this->position,
        ];
    }
}
