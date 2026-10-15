<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BannerLinkType;
use App\Enums\BannerPosition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title', 'image_path', 'link_type', 'link_value', 'position', 'sort_order', 'starts_at', 'ends_at', 'is_active'])]
class Banner extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'link_type' => BannerLinkType::class,
            'position' => BannerPosition::class,
            'sort_order' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
