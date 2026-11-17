<?php

declare(strict_types=1);

namespace App\Services\Wholesale;

use App\Models\Quotation;

/**
 * Computes deterministic revision hashes for quotation payloads to guarantee optimistic concurrency.
 */
final class QuotationVersion
{
    /**
     * Generates a SHA-256 fingerprint representing the current pricing, quantity, and status state.
     *
     * @param  Quotation  $quotation  Quotation instance to fingerprint.
     * @return string Hex-encoded SHA-256 hash.
     */
    public static function hash(Quotation $quotation): string
    {
        $quotation->loadMissing('items');
        // Sort items by ID to guarantee identical hash calculation across different query orders.
        $items = $quotation->items
            ->sortBy('id')
            ->map(fn ($item) => $item->id.'|'.$item->qty.'|'.$item->offered_unit_price.'|'.$item->requested_target_price)
            ->implode(';');

        // Include status, revision, totals, valid_until, updated_at, and item payload to invalidate proposals if modified concurrently.
        return hash('sha256', implode('|', [
            $quotation->status->value,
            (int) $quotation->revision,
            (string) $quotation->discount_amount,
            (string) $quotation->tax_amount,
            (string) $quotation->grand_total,
            $quotation->valid_until?->toDateString() ?? '',
            $quotation->updated_at?->toIso8601String() ?? '',
            $items,
        ]));
    }
}
