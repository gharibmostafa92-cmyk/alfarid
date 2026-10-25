<?php

declare(strict_types=1);

namespace App\Http\Resources\Store;

use App\Enums\BannerLinkType;
use App\Support\BannerImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'image' => BannerImage::url($this->image_path),
            'position' => $this->position,
            'link_type' => $this->link_type,
            'link_value' => $this->link_value,
            'href' => $this->resolved_href ?? $this->getAttribute('resolved_href'),
            'is_external' => $this->link_type === BannerLinkType::External,
            'kicker' => $this->kicker ?? null,
            'headline' => $this->headline ?? null,
            'headline_bold' => $this->headline_bold ?? null,
            'description' => $this->description ?? null,
            'cta_label' => $this->cta_label ?? null,
            'bg_color' => $this->bg_color ?? null,
        ];
    }
}
