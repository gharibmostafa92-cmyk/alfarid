<?php

declare(strict_types=1);

namespace App\Services\Wholesale;

use App\Models\Quotation;

/**
 * Computes deterministic revision hashes for quotation payloads to guarantee optimistic concurrency.
 */
final class QuotationVersion
{
    public static function hash(Quotation $quotation): string
    {
        $quotation->loadMissing('items');
        $items = $quotation->items
            ->sortBy('id')
            ->map(fn ($item) => $item->id.'|'.$item->qty.'|'.$item->offered_unit_price.'|'.$item->requested_target_price)
            ->implode(';');

        return hash('sha256', $quotation->status->value.'|'.$quotation->updated_at?->toIso8601String().'|'.$items);
    }
}
