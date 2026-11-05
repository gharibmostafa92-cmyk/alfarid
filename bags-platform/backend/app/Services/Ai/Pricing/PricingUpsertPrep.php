<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing;

use App\Models\PriceList;
use App\Models\PriceListItem;
use Illuminate\Support\Collection;

/**
 * Builds the optimistic-lock versions + undo inverse for upsert proposals
 * created by importer tools (vision / CSV) that bypass
 * UpsertPriceListItemsTool::prepare and propose upsert rows directly.
 *
 * @see \App\Services\Ai\Pricing\Tools\UpsertPriceListItemsTool
 */
final class PricingUpsertPrep
{
    /**
     * @param  list<array{variant_id: int, min_qty: float}>  $rows
     * @param  Collection<int, PriceListItem>  $current  keyed "variantId:min_qty"
     * @return array{expectedVersions: array<string,string>, inverse: array{updated: array<string,array<string,mixed>>, created: list<int>}}
     */
    public static function versionsAndInverse(PriceList $list, array $rows, Collection $current): array
    {
        $expectedVersions = ['pl:'.$list->id => PricingFingerprint::priceList($list)];
        $inverse = ['updated' => [], 'created' => []];
        foreach ($rows as $row) {
            $old = $current->get(((int) $row['variant_id']).':'.number_format((float) $row['min_qty'], 4, '.', ''));
            if ($old instanceof PriceListItem) {
                $expectedVersions['pli:'.$old->id] = PricingFingerprint::item($old);
                $inverse['updated'][(string) $old->id] = [
                    'product_variant_id' => (int) $old->product_variant_id,
                    'min_qty' => (float) $old->min_qty,
                    'price' => (float) $old->price,
                    'starts_at' => $old->starts_at?->format('Y-m-d H:i:s'),
                    'ends_at' => $old->ends_at?->format('Y-m-d H:i:s'),
                ];
            }
        }

        return ['expectedVersions' => $expectedVersions, 'inverse' => $inverse];
    }
}
