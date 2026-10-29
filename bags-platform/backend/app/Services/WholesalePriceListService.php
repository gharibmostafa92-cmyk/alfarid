<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProductStatus;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\StockLevel;

/**
 * Generates customer-specific wholesale price list export rows.
 * Acts as the single source of truth for both Excel and PDF price list exports.
 */
class WholesalePriceListService
{
    public function __construct(private readonly PricingService $pricing) {}

    /**
     * Build tabular pricing and availability rows for all active variants for the given customer.
     *
     * @return list<array{sku: string, barcode: string|null, product_name: string|null, base_price: float|null, wholesale_price: float|null, tier_prices: list<array{min_qty: float, unit_price: float}>, available_qty: float}>
     */
    public function rows(Customer $customer): array
    {
        $variants = ProductVariant::query()->with('product')
            ->where('is_active', true)
            ->whereHas('product', fn ($q) => $q->where('status', ProductStatus::Published->value))
            ->orderBy('sku')
            ->get();

        $availability = StockLevel::query()
            ->whereIn('product_variant_id', $variants->pluck('id')->all())
            ->groupBy('product_variant_id')
            ->selectRaw('product_variant_id, SUM(available) AS total_available')
            ->pluck('total_available', 'product_variant_id')
            ->map(fn ($v) => (float) $v)
            ->all();

        // F17: one context for the whole export, not one group lookup per row.
        $ctx = $this->pricing->contextFor($customer, $variants->pluck('id')->all());

        return $variants->map(function (ProductVariant $variant) use ($customer, $availability, $ctx): array {
            try {
                $resolved = $this->pricing->resolve($variant, $customer, 1, null, $ctx);
                $price = $resolved['unit_price'];
                $base = $resolved['base_price'];
                $tiers = $this->pricing->tiersFor($variant, $customer, $ctx);
            } catch (BusinessException) {
                // Unpriced variant: emit with null price rather than failing the entire export
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
