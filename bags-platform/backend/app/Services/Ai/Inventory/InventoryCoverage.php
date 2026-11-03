<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory;

use App\Enums\StockMovementType;
use App\Models\StockLevel;
use Illuminate\Support\Facades\DB;

/**
 * أيام التغطية = المتاح ÷ متوسط البيع اليومي (آخر 30 يوم).
 * null تعني بلا مبيعات (تغطية غير محدودة عمليًا).
 */
final class InventoryCoverage
{
    public static function avgDailySale(int $variantId, ?int $branchId = null, int $days = 30): float
    {
        // L5: net sales — customer returns (positive sale_return quantities)
        // offset gross sales, otherwise velocity (and transfer suggestions)
        // are overstated.
        $sold = (float) DB::table('stock_movements')
            ->where('product_variant_id', $variantId)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->whereIn('type', [StockMovementType::Sale->value, StockMovementType::SaleReturn->value])
            ->where('created_at', '>=', now()->subDays($days))
            ->sum(DB::raw('-quantity'));

        return max(0.0, $sold / max(1, $days));
    }

    public static function available(int $variantId, int $branchId): float
    {
        return (float) StockLevel::query()
            ->where('product_variant_id', $variantId)
            ->where('branch_id', $branchId)
            ->sum(DB::raw('on_hand - reserved - damaged'));
    }

    /** @return float|null */
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
