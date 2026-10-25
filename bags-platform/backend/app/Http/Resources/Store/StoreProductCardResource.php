<?php

declare(strict_types=1);

namespace App\Http\Resources\Store;

use App\Services\StoreCatalogService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * بطاقة منتج في المتجر: السعر النهائي عبر PricingService للعميل الحالي
 * (زائر = قناة قطاعي، وعميل بتوكن = مجموعته/أسعاره الخاصة تسرى عليه).
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
            // F6: conversions render on the queue — fall back to the original
            // until the thumb exists instead of emitting a broken CDN link.
            'image' => ($media = $this->resource->catalogImages()->first()) && $media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : ($media?->getUrl() ?: null),
            'brand' => null,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id, 'name_ar' => $this->category->name_ar, 'name_en' => $this->category->name_en, 'slug' => $this->category->slug,
            ] : null),
            'price' => $display['price'] ?? null,
            'default_variant_id' => $display['variant_id'] ?? null,
            'old_price' => $display !== null && $display['compare_at'] !== null && $display['compare_at'] > $display['price']
                ? $display['compare_at'] : null,
            'discount_percentage' => $display['discount_percentage'] ?? null,
            'is_featured' => (bool) $this->is_featured,
            'sales_count' => (int) $this->sales_count,
            'in_stock' => $available > 0,
        ];
    }
}
