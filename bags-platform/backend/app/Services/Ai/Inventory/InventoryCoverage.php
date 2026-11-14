<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory;

use App\Enums\Inventory\StockMovementType;
use App\Models\StockLevel;
use Illuminate\Support\Facades\DB;

/**
 * Calculate stock coverage days: available inventory divided by average daily net sales (null when zero sales).
 */
final class InventoryCoverage
{
    /**
     * Compute average daily net sales velocity over the given lookback window.
     */
    public static function avgDailySale(int $variantId, ?int $branchId = null, int $days = 30): float
    {
        // Factor customer returns (positive sale_return quantities) against gross sales so
        // net sales velocity and automated replenishment suggestions remain accurate.
        $sold = (float) DB::table('stock_movements')
            ->where('product_variant_id', $variantId)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->whereIn('type', [StockMovementType::Sale->value, StockMovementType::SaleReturn->value])
            ->where('created_at', '>=', now()->subDays($days))
            ->sum(DB::raw('-quantity'));

        return max(0.0, $sold / max(1, $days));
    }

    /**
     * Compute net available stock across locations for a variant in a branch.
     */
    public static function available(int $variantId, int $branchId): float
    {
        return (float) StockLevel::query()
            ->where('product_variant_id', $variantId)
            ->where('branch_id', $branchId)
            ->sum(DB::raw('on_hand - reserved - damaged'));
    }

    /**
     * Estimated days of stock coverage before stockout.
     *
     * @return float|null
     */
    public static function coverageDays(int $variantId, int $branchId, int $days = 30): ?float
    {
        return self::metricsByBranch($variantId, [$branchId], $days)[$branchId]['coverage_days'];
    }

    /**
     * Calculate stock and sales coverage for multiple branches in two grouped queries.
     *
     * @param list<int> $branchIds
     * @return array<int, array{available: float, avg_daily_sale: float, coverage_days: ?float}>
     */
    public static function metricsByBranch(int $variantId, array $branchIds, int $days = 30): array
    {
        $branchIds = array_values(array_unique(array_map('intval', $branchIds)));
        if ($branchIds === []) {
            return [];
        }

        $availableByBranch = StockLevel::query()->where('product_variant_id', $variantId)
            ->whereIn('branch_id', $branchIds)
            ->selectRaw('branch_id, SUM(on_hand - reserved - damaged) AS available')
            ->groupBy('branch_id')->pluck('available', 'branch_id');
        $soldByBranch = DB::table('stock_movements')
            ->where('product_variant_id', $variantId)
            ->whereIn('branch_id', $branchIds)
            ->whereIn('type', [StockMovementType::Sale->value, StockMovementType::SaleReturn->value])
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('branch_id, SUM(-quantity) AS net_sold')
            ->groupBy('branch_id')->pluck('net_sold', 'branch_id');

        $metrics = [];
        foreach ($branchIds as $branchId) {
            $available = (float) ($availableByBranch[$branchId] ?? 0);
            $daily = max(0.0, (float) ($soldByBranch[$branchId] ?? 0) / max(1, $days));
            $metrics[$branchId] = [
                'available' => $available,
                'avg_daily_sale' => $daily,
                'coverage_days' => $daily <= 0 ? null : ($available <= 0 ? 0.0 : round($available / $daily, 1)),
            ];
        }

        return $metrics;
    }
}
