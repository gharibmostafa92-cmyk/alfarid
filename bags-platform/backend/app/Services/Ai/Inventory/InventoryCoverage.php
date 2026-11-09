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
        $available = self::available($variantId, $branchId);
        $daily = self::avgDailySale($variantId, $branchId, $days);
        if ($daily <= 0) {
            return null;
        }
        if ($available <= 0) {
            return 0.0;
        }

        return round($available / $daily, 1);
    }
}
