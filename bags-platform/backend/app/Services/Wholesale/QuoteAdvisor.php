<?php

declare(strict_types=1);

namespace App\Services\Wholesale;

use App\Enums\Wholesale\QuotationStatus;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\Shared\CostAccess;
use App\Services\Pricing\PricingService;
use App\Services\Shared\SettingService;
use Illuminate\Support\Facades\DB;

/**
 * Recommends line item pricing for wholesale quotations based on cost bounds, tier prices, and historical acceptance.
 */
final class QuoteAdvisor
{
    public function __construct(
        private PricingService $pricing,
        private SettingService $settings,
    ) {}

    /**
     * Evaluates quotation line items and suggests optimal pricing based on list prices, costs, and historical rates.
     *
     * @param  Quotation  $quotation  Quotation request requiring line pricing.
     * @param  User|null  $actor  Staff user reviewing the quote (used for cost visibility authorization).
     * @return array{lines: list<array<string, mixed>>, warnings: list<string>} Line suggestions with rationale and warnings.
     */
    public function suggest(Quotation $quotation, ?User $actor): array
    {
        $quotation->loadMissing(['items.variant', 'customer']);
        // Hide cost price when the acting staff user lacks authorization, preventing profit margin leakage.
        $canCost = CostAccess::canView($actor);
        $policy = (string) $this->settings->get('ai_price_below_cost_policy', 'block');
        $variantIds = $quotation->items->pluck('product_variant_id')->filter()->unique()->values()->all();
        $customerId = (int) $quotation->customer_id;

        $stockMap = $this->batchAvailable($variantIds);
        $lastSoldMap = $this->batchLastSold($customerId, $variantIds);
        $acceptanceMap = $this->batchAcceptanceRates($customerId, $variantIds);

        $lines = [];
        foreach ($quotation->items as $item) {
            $variant = $item->variant;
            $qty = (float) $item->qty;
            $resolved = $variant instanceof ProductVariant
                ? $this->pricing->resolve($variant, $quotation->customer, $qty)
                : ['unit_price' => 0.0, 'base_price' => 0.0, 'source' => 'missing'];
            $list = round((float) $resolved['unit_price'], 2);
            $requested = $item->requested_target_price !== null ? round((float) $item->requested_target_price, 2) : null;
            $cost = $canCost && $variant !== null ? round((float) $variant->cost_price, 2) : null;
            $suggested = $list;
            $reason = 'سعر القائمة عند الكمية المطلوبة';
            // Float epsilon (+0.001) avoids rounding jitter when requested price matches unit cost exactly.
            if ($requested !== null && ($cost === null || $requested + 0.001 >= $cost || $policy === 'warn')) {
                if ($requested <= $list) {
                    $suggested = $requested;
                    $reason = 'السعر الذي طلبه التاجر';
                }
            } elseif ($requested !== null && $cost !== null && $requested < $cost && $policy === 'block') {
                $reason = 'السعر المطلوب أقل من التكلفة، والسياسة تمنع النزول تحتها';
            }
            $variantId = (int) $item->product_variant_id;
            $lines[] = [
                'quotation_item_id' => $item->id,
                'sku' => $variant?->sku,
                'list_price' => $list,
                'requested_price' => $requested,
                'suggested_price' => $suggested,
                'cost' => $cost,
                'stock_available' => $stockMap[$variantId] ?? 0.0,
                'last_sold_price' => $lastSoldMap[$variantId] ?? null,
                'acceptance_rate' => $acceptanceMap[$variantId] ?? null,
                'reason' => $reason,
            ];
        }

        return ['lines' => $lines, 'warnings' => []];
    }

    /**
     * @param  list<int>  $variantIds
     * @return array<int, float>
     */
    private function batchAvailable(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        // Net available stock excludes both reserved units and damaged inventory.
        return StockLevel::query()
            ->whereIn('product_variant_id', $variantIds)
            ->groupBy('product_variant_id')
            ->selectRaw('product_variant_id, COALESCE(SUM(COALESCE(on_hand, 0) - COALESCE(reserved, 0) - COALESCE(damaged, 0)), 0) as available')
            ->pluck('available', 'product_variant_id')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();
    }

    /**
     * @param  list<int>  $variantIds
     * @return array<int, float>
     */
    private function batchLastSold(int $customerId, array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $latestItemIds = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.customer_id', $customerId)
            ->whereNotIn('orders.status', ['cancelled', 'draft'])
            ->whereIn('order_items.product_variant_id', $variantIds)
            ->groupBy('order_items.product_variant_id')
            ->selectRaw('MAX(order_items.id) as max_id')
            ->pluck('max_id');

        if ($latestItemIds->isEmpty()) {
            return [];
        }

        return OrderItem::query()
            ->whereIn('id', $latestItemIds)
            ->pluck('unit_price', 'product_variant_id')
            ->map(fn ($price) => round((float) $price, 2))
            ->all();
    }

    /**
     * @param  list<int>  $variantIds
     * @return array<int, float>
     */
    private function batchAcceptanceRates(int $customerId, array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        // Exclude open/draft quotations from denominator so in-flight negotiations do not depress acceptance rate.
        $rows = DB::table('quotation_items')
            ->join('quotations', 'quotations.id', '=', 'quotation_items.quotation_id')
            ->where('quotations.customer_id', $customerId)
            ->whereIn('quotation_items.product_variant_id', $variantIds)
            ->whereIn('quotations.status', [
                QuotationStatus::Accepted->value,
                QuotationStatus::Rejected->value,
                QuotationStatus::Expired->value,
            ])
            ->selectRaw('quotation_items.product_variant_id, quotations.status, count(*) as c')
            ->groupBy('quotation_items.product_variant_id', 'quotations.status')
            ->get();

        $rates = [];
        $byVariant = $rows->groupBy('product_variant_id');
        foreach ($byVariant as $vId => $statusCounts) {
            $total = (int) $statusCounts->sum('c');
            if ($total > 0) {
                $accepted = (int) ($statusCounts->firstWhere('status', QuotationStatus::Accepted->value)?->c ?? 0);
                $rates[(int) $vId] = round(($accepted / $total) * 100, 1);
            }
        }

        return $rates;
    }
}
