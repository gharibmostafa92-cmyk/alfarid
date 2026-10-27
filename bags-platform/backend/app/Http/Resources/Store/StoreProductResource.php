<?php

declare(strict_types=1);

namespace App\Http\Resources\Store;

use App\Exceptions\BusinessException;
use App\Services\CartService;
use App\Services\StoreCatalogService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** صفحة منتج كاملة: كل متغير بسعره النهائي ومتاحه أونلاين. */
class StoreProductResource extends JsonResource
{
    /**
     * خريطة متاح المتغيرات (يضبطها المتحكم من StoreCatalogService).
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
                // F6: fall back to the original until the queued conversion exists.
                'thumb' => $media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : $media->getUrl(),
            ])->all(),
            'variants' => $this->variants->map(function ($variant) use ($pricing, $customer, $carts) {
                $resolved = null;
                try {
                    $resolved = $pricing->variantPrice($variant, $customer);
                } catch (BusinessException) {
                    // متغير غير مُسعَّر: يظهر بلا سعر بدل كسر صفحة المنتج
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
                    // F23: نفس معادلة البطاقة عبر StoreCatalogService::discountPercentage.
                    'old_price' => $resolved !== null && StoreCatalogService::discountPercentage((float) $resolved['unit_price'], max((float) $variant->compare_at_price, (float) $resolved['base_price'])) !== null
                        ? max((float) $variant->compare_at_price, (float) $resolved['base_price']) : null,
                    'in_stock' => $available > 0,
                    'max_qty' => $resolved === null ? 0 : $carts->maxPurchasableQty($available, $customer),
                ];
            })->all(),
        ];
    }
}
