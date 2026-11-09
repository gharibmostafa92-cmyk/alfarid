<?php

declare(strict_types=1);

namespace App\Http\Resources\Store\Catalog;

use App\Services\Catalog\StoreCatalogService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Storefront product card resource.
 *
 * Resolves context-aware customer pricing (guest retail vs authenticated customer group tier) and stock availability.
 */
class StoreProductCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $service = app(StoreCatalogService::class);
        $customer = $service->customer();

        $display = $service->displayPrice($this->resource, $customer);

        $available = $this->store_available !== null
            ? (float) $this->store_available
            : array_sum($service->availabilityMap($this->variants->pluck('id')->all()));

        return [
            'id' => $this->id,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'slug' => $this->slug,
            // Conversions render asynchronously; fall back to original until thumb exists.
            'image' => ($media = $this->resource->catalogImages()->first()) && $media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : ($media?->getUrl() ?: null),
            'brand' => null,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id, 'name_ar' => $this->category->name_ar, 'name_en' => $this->category->name_en, 'slug' => $this->category->slug,
            ] : null),
            'price' => $display['price'] ?? null,
            'default_variant_id' => $display['variant_id'] ?? null,
            'variant_count' => $this->variants->count(),
            'old_price' => $display !== null && $display['compare_at'] !== null && $display['compare_at'] > $display['price']
                ? $display['compare_at'] : null,
            'discount_percentage' => $display['discount_percentage'] ?? null,
            'is_featured' => (bool) $this->is_featured,
            // Expose binary stock presence rather than exact inventory counts to public storefront clients.
            'in_stock' => $available > 0,
            'is_new' => $this->published_at !== null
                && $this->published_at->gte(now()->subDays((int) config('storefront.new_days', 30))),
            'badge_en' => $this->badge_en,
            'badge_ar' => $this->badge_ar,
            'colors' => $this->relationLoaded('variants')
                ? $this->variants
                    ->filter(fn ($v) => filled($v->color_name_en) || filled($v->color_hex))
                    ->unique(fn ($v) => mb_strtolower((string) $v->color_name_en).'|'.$v->color_hex)
                    ->map(fn ($v) => ['name_en' => $v->color_name_en, 'name_ar' => $v->color_name_ar, 'hex' => $v->color_hex])
                    ->values()->all()
                : [],
        ];
    }
}
