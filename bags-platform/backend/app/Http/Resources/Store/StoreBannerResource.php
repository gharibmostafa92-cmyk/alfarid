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
            'image' => Storage::disk('r2')->temporaryUrl($this->image_path, now()->addSeconds(
                max(60, min(604800, (int) config('filesystems.disks.r2.download_url_ttl_seconds', 3600)))
            )),
            'link_type' => $this->link_type,
            'link_value' => $this->link_value,
            'position' => $this->position,
        ];
    }
}
