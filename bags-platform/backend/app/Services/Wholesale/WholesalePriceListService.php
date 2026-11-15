<?php

declare(strict_types=1);

namespace App\Services\Wholesale;

use App\Services\Finance\StatementBudget;
use App\Services\Pricing\PricingService;

use App\Enums\Catalog\ProductStatus;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\StockLevel;

/**
 * Generates customer-specific wholesale price list export rows.
 *
 * Serves as the single source of truth for Excel and PDF price list exports.
 */
class WholesalePriceListService
{
    public function __construct(private readonly PricingService $pricing) {}

    /**
     * Build tabular pricing and availability rows for all active variants for the given customer.
     *
     * @param  Customer  $customer  Target wholesale customer account.
     * @return list<array{sku: string, barcode: string|null, product_name: string|null, base_price: float|null, wholesale_price: float|null, tier_prices: list<array{min_qty: float, unit_price: float}>, available_qty: float}>
     */
    public function rows(Customer $customer, ?int $limit = null): array
    {
        return StatementBudget::run(150000, fn (): array => $this->calculateRows($customer, $limit));
    }

    private function calculateRows(Customer $customer, ?int $limit): array
    {
        $maxRows = $limit !== null ? min($limit, 10000) : 10000;
        $rows = [];

        ProductVariant::query()->with('product')
            ->wholesalePurchasable()
            ->orderBy('id')
            ->chunkById(500, function ($variants) use ($customer, &$rows, $maxRows): bool {
                $remaining = $maxRows - count($rows);
                if ($remaining <= 0) {
                    return false;
                }
                if ($variants->count() > $remaining) {
                    $variants = $variants->take($remaining);
                }

                $variantIds = $variants->pluck('id')->all();
                $availability = StockLevel::query()
                    ->whereIn('product_variant_id', $variantIds)
                    ->groupBy('product_variant_id')
                    ->selectRaw('product_variant_id, SUM(available) AS total_available')
                    ->pluck('total_available', 'product_variant_id')
                    ->map(fn ($v) => (float) $v)
                    ->all();

                // Preload customer pricing rules and tier overrides once per chunk to eliminate N+1 group queries.
                $ctx = $this->pricing->contextFor($customer, $variantIds);

                foreach ($variants as $variant) {
                    try {
                        $resolved = $this->pricing->resolve($variant, $customer, 1, null, $ctx);
                        $price = $resolved['unit_price'];
                        $base = $resolved['base_price'];
                        $tiers = $this->pricing->tiersFor($variant, $customer, $ctx);
                    } catch (BusinessException) {
                        // Emit unpriced variants with null instead of aborting the bulk catalog export.
                        $price = null;
                        $base = $variant->retail_price !== null ? (float) $variant->retail_price : null;
                        $tiers = [];
                    }

                    $rows[] = [
                        'sku' => $variant->sku,
                        'barcode' => $variant->barcode,
                        'product_name' => $variant->product?->name_ar,
                        'base_price' => $base,
                        'wholesale_price' => $price,
                        'tier_prices' => $tiers,
                        'available_qty' => $availability[$variant->id] ?? 0.0,
                    ];
                }

                return count($rows) < $maxRows;
            });

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['sku'], (string) $b['sku']));

        return $rows;
    }
}
