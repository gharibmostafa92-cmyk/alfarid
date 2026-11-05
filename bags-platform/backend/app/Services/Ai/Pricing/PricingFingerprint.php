<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing;

use App\Models\Coupon;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Promotion;
use Illuminate\Support\Arr;

final class PricingFingerprint
{
    public static function priceList(PriceList $l): string
    {
        return self::h(Arr::only($l->getRawOriginal(), ['name', 'type', 'customer_id', 'customer_group_id', 'branch_id', 'starts_at', 'ends_at', 'priority', 'is_active']));
    }

    public static function item(PriceListItem $i): string
    {
        return self::h(Arr::only($i->getRawOriginal(), ['product_variant_id', 'min_qty', 'price', 'starts_at', 'ends_at']));
    }

    public static function coupon(Coupon $c): string
    {
        return self::h(Arr::only($c->getRawOriginal(), ['code', 'type', 'value', 'min_order_amount', 'max_discount_amount', 'usage_limit', 'usage_limit_per_customer', 'starts_at', 'ends_at', 'is_active']));
    }

    public static function promotion(Promotion $p): string
    {
        return self::h(Arr::only($p->getRawOriginal(), ['name', 'type', 'value', 'min_order_amount', 'applies_to', 'target_ids', 'starts_at', 'ends_at', 'is_active']));
    }

    private static function h(array $a): string
    {
        ksort($a);

        return hash('sha256', json_encode($a, JSON_THROW_ON_ERROR));
    }
}
