<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BannerLinkType;
use App\Enums\BannerPosition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

#[Fillable(['title', 'image_path', 'link_type', 'link_value', 'position', 'sort_order', 'starts_at', 'ends_at', 'is_active', 'kicker', 'headline', 'headline_bold', 'description', 'cta_label', 'bg_color'])]
class Banner extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        $flush = static function (): void {
            DB::afterCommit(static function (): void {
                Cache::forget('store_home_data');
                Cache::forget('illuminate:cache:flexible:created:store_home_data');
            });
        };
        static::saved($flush);
        static::deleted($flush);
    }

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
