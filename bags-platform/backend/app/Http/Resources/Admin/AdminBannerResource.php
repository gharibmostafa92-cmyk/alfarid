<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Support\BannerImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminBannerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $now = now();
        $status = match (true) {
            ! $this->is_active => 'disabled',
            $this->starts_at && $this->starts_at->gt($now) => 'scheduled',
            $this->ends_at && $this->ends_at->lt($now) => 'expired',
            default => 'live',
        };

        return [
            'id' => $this->id,
            'title' => $this->title,
            'image_url' => BannerImage::url($this->image_path),
            'position' => $this->position,
            'link_type' => $this->link_type,
            'link_value' => $this->link_value,
            'link_label' => $this->link_label ?? $this->getAttribute('link_label'),
            'sort_order' => $this->sort_order,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'is_active' => $this->is_active,
            'status' => $status,
            'kicker' => $this->kicker ?? null,
            'headline' => $this->headline ?? null,
            'headline_bold' => $this->headline_bold ?? null,
            'description' => $this->description ?? null,
            'cta_label' => $this->cta_label ?? null,
            'bg_color' => $this->bg_color ?? null,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
