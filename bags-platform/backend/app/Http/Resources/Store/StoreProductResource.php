<?php

declare(strict_types=1);

namespace App\Http\Resources\Store;

use App\Exceptions\BusinessException;
use App\Services\Orders\CartService;
use App\Services\Catalog\StoreCatalogService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full product detail resource for the storefront, mapping variants, pricing tiers, media, and stock.
 */
class StoreProductResource extends JsonResource
{
    /**
     * Map of variant stock availability injected by the controller from StoreCatalogService.
     *
     * @var array<int, float>
     */
    public array $availability = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pricing = app(StoreCatalogService::class);
        $customer = $pricing->customer();
        $carts = app(CartService::class);

        $card = (new StoreProductCardResource($this->resource))->resolve($request);

        return [
            ...$card,
            'description_ar' => $this->description_ar,
            'description_en' => $this->description_en,
            'material_ar' => $this->material_ar,
            'material_en' => $this->material_en,
            'warranty_months' => $this->warranty_months,
            'occasions' => $this->occasions ?? [],
            'warranty_details' => $this->warranty_details,
            'dimensions' => [
                'length_cm' => $this->length_cm !== null ? (float) $this->length_cm : null,
                'width_cm' => $this->width_cm !== null ? (float) $this->width_cm : null,
                'height_cm' => $this->height_cm !== null ? (float) $this->height_cm : null,
                'weight_kg' => $this->weight_kg !== null ? (float) $this->weight_kg : null,
            ],
            'country_of_origin' => $this->country_of_origin,
            'images' => $this->resource->catalogImages()->map(fn ($media) => [
                'id' => $media->id,
                'url' => $media->getUrl(),
                // Conversions generate asynchronously; fall back to original until thumbnail is ready.
                'thumb' => $media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : $media->getUrl(),
            ])->all(),
            'variants' => $this->variants->map(function ($variant) use ($pricing, $customer, $carts) {
                $resolved = null;
                try {
                    $resolved = $pricing->variantPrice($variant, $customer);
                } catch (BusinessException) {
                    // Omit price when pricing cannot be resolved so the product page remains functional.
                }
                $available = (float) ($this->availability[$variant->id] ?? $variant->store_available ?? 0);

                return [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'color_name_ar' => $variant->color_name_ar,
                    'color_name_en' => $variant->color_name_en,
                    'color_hex' => $variant->color_hex,
                    'size' => $variant->size,
                    'capacity' => $variant->capacity,
                    'images' => $variant->getMedia('images')->map(fn ($media) => [
                        'id' => $media->id,
                        'url' => $media->getUrl(),
                        'thumb' => $media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : $media->getUrl(),
                    ])->values()->all(),
                    'price' => $resolved !== null ? (float) $resolved['unit_price'] : null,
                    // Derive old price consistently with card display via discountPercentage comparison.
                    'old_price' => $resolved !== null && StoreCatalogService::discountPercentage((float) $resolved['unit_price'], max((float) $variant->compare_at_price, (float) $resolved['base_price'])) !== null
                        ? max((float) $variant->compare_at_price, (float) $resolved['base_price']) : null,
                    'in_stock' => $available > 0,
                    'max_qty' => $resolved === null ? 0 : $carts->maxPurchasableQty($available, $customer),
                ];
            })->all(),
        ];
    }
}
