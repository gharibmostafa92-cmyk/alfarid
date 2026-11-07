<?php

declare(strict_types=1);

namespace App\Services\Wholesale;

use App\Enums\QuotationStatus;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\CostAccess;
use App\Services\PricingService;
use App\Services\SettingService;
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
     * @return array{lines: list<array<string, mixed>>, warnings: list<string>}
     */
    public function suggest(Quotation $quotation, ?User $actor): array
    {
        $quotation->loadMissing(['items.variant', 'customer']);
        $canCost = CostAccess::canView($actor);
        $policy = (string) $this->settings->get('ai_price_below_cost_policy', 'block');
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
            if ($requested !== null && ($cost === null || $requested + 0.001 >= $cost || $policy === 'warn')) {
                if ($requested <= $list) {
                    $suggested = $requested;
                    $reason = 'السعر الذي طلبه التاجر';
                }
            } elseif ($requested !== null && $cost !== null && $requested < $cost && $policy === 'block') {
                $reason = 'السعر المطلوب أقل من التكلفة، والسياسة تمنع النزول تحتها';
            }
            $stock = $this->available($item->product_variant_id);
            $lines[] = [
                'quotation_item_id' => $item->id,
                'sku' => $variant?->sku,
                'list_price' => $list,
                'requested_price' => $requested,
                'suggested_price' => $suggested,
                'cost' => $cost,
                'stock_available' => $stock,
                'last_sold_price' => $this->lastSold($quotation->customer_id, $item->product_variant_id),
                'acceptance_rate' => $this->acceptanceRate($quotation->customer_id, $item->product_variant_id),
                'reason' => $reason,
            ];
        }

        return ['lines' => $lines, 'warnings' => []];
    }

    private function available(int $variantId): float
    {
        $available = StockLevel::query()
            ->where('product_variant_id', $variantId)
            ->sum(DB::raw('COALESCE(on_hand, 0) - COALESCE(reserved, 0) - COALESCE(damaged, 0)'));

        return round((float) $available, 2);
    }

    private function lastSold(int $customerId, int $variantId): ?float
    {
        $price = OrderItem::query()
            ->where('product_variant_id', $variantId)
            ->whereHas('order', fn ($q) => $q->where('customer_id', $customerId)->whereNotIn('status', ['cancelled', 'draft']))
            ->latest('id')
            ->value('unit_price');

        return $price === null ? null : round((float) $price, 2);
    }

    private function acceptanceRate(int $customerId, int $variantId): ?float
    {
        $rows = DB::table('quotation_items')
            ->join('quotations', 'quotations.id', '=', 'quotation_items.quotation_id')
            ->where('quotations.customer_id', $customerId)
            ->where('quotation_items.product_variant_id', $variantId)
            ->whereIn('quotations.status', [
                QuotationStatus::Accepted->value,
                QuotationStatus::Rejected->value,
                QuotationStatus::Expired->value,
            ])
            ->selectRaw('quotations.status, count(*) as c')
            ->groupBy('quotations.status')
            ->pluck('c', 'status');
        $total = (int) $rows->sum();
        if ($total === 0) {
            return null;
        }

        return round(((int) ($rows[QuotationStatus::Accepted->value] ?? 0) / $total) * 100, 1);
    }
}
