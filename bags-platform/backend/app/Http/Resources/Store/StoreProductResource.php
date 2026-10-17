<?php

declare(strict_types=1);

namespace App\Http\Resources\Store;

use App\Exceptions\BusinessException;
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

        $card = (new StoreProductCardResource($this->resource))->resolve($request);

        return [
            ...$card,
            'description_ar' => $this->description_ar,
            'description_en' => $this->description_en,
            'material_ar' => $this->material_ar,
            'material_en' => $this->material_en,
            'warranty_months' => $this->warranty_months,
            'country_of_origin' => $this->country_of_origin,
            'images' => $this->getMedia('images')->map(fn ($media) => [
                'id' => $media->id,
                'url' => $media->getUrl(),
                'thumb' => $media->getUrl('thumb'),
            ])->all(),
            'variants' => $this->variants->map(function ($variant) use ($pricing, $customer) {
                $resolved = null;
                try {
                    $resolved = $pricing->variantPrice($variant, $customer);
                } catch (BusinessException) {
                    // متغير غير مُسعَّر: يظهر بلا سعر بدل كسر صفحة المنتج
                }
                $available = (float) ($this->availability[$variant->id] ?? 0);

                return [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'color_name_ar' => $variant->color_name_ar,
                    'color_name_en' => $variant->color_name_en,
                    'color_hex' => $variant->color_hex,
                    'size' => $variant->size,
                    'capacity' => $variant->capacity,
                    'price' => $resolved !== null ? (float) $resolved['unit_price'] : null,
                    'old_price' => $resolved !== null && max((float) $variant->compare_at_price, (float) $resolved['base_price']) > (float) $resolved['unit_price']
                        && (int) round((1 - $resolved['unit_price'] / max((float) $variant->compare_at_price, (float) $resolved['base_price'])) * 100) >= 1
                        ? max((float) $variant->compare_at_price, (float) $resolved['base_price']) : null,
                    'in_stock' => $available > 0,
                ];
            })->all(),
        ];
    }
}
