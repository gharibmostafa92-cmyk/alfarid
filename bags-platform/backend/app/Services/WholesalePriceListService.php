<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\StockLevel;

/**
 * صفوف قائمة أسعار الجملة لتاجر بعينه (المرحلة 12.2): كل متغير نشط لمنتج
 * منشور بسعره الخاص بهذا التاجر وشرائح كمياته — مصدر موحّد لتصديرَي
 * Excel وPDF حتى لا يختلفا أبدًا.
 */
class WholesalePriceListService
{
    public function __construct(private readonly PricingService $pricing) {}

    /**
     * @return list<array{sku: string, barcode: string|null, product_name: string|null, base_price: float|null, wholesale_price: float|null, tier_prices: list<array{min_qty: float, unit_price: float}>, available_qty: float}>
     */
    public function rows(Customer $customer): array
    {
        $variants = ProductVariant::query()->with('product')
            ->where('is_active', true)
            ->whereHas('product', fn ($q) => $q->where('status', 'published'))
            ->orderBy('sku')
            ->get();

        $availability = StockLevel::query()
            ->whereIn('product_variant_id', $variants->pluck('id')->all())
            ->groupBy('product_variant_id')
            ->selectRaw('product_variant_id, SUM(available) AS total_available')
            ->pluck('total_available', 'product_variant_id')
            ->map(fn ($v) => (float) $v)
            ->all();

        return $variants->map(function (ProductVariant $variant) use ($customer, $availability): array {
            try {
                $resolved = $this->pricing->resolve($variant, $customer, 1);
                $price = $resolved['unit_price'];
                $base = $resolved['base_price'];
                $tiers = $this->pricing->tiersFor($variant, $customer);
            } catch (BusinessException) {
                // متغير غير مُسعَّر: يظهر في القائمة بلا سعر بدل إسقاط التصدير كله
                $price = null;
                $base = $variant->retail_price !== null ? (float) $variant->retail_price : null;
                $tiers = [];
            }

            return [
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'product_name' => $variant->product?->name_ar,
                'base_price' => $base,
                'wholesale_price' => $price,
                'tier_prices' => $tiers,
                'available_qty' => $availability[$variant->id] ?? 0.0,
            ];
        })->all();
    }
}
