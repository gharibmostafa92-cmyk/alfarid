<?php

declare(strict_types=1);

namespace App\Http\Resources\Store\Orders;

use App\Services\Pos\StorePaymentPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public order status projection for customer tracking portals.
 *
 * Excludes customer PII, addresses, totals, and internal notes to prevent data leakage on unauthenticated lookups.
 */
class TrackOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $instapay = app(StorePaymentPolicy::class)->instapayInstructions($this->resource);

        return [
            'order_number' => $this->order_number,
            'status' => $this->status,
            'instapay' => $this->when($instapay !== null, $instapay),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'product_name' => $item->product_name_snapshot,
                'qty' => $item->qty,
            ])->all()),
            // Internal admin/system notes are intentionally omitted from public tracking payloads.
            'status_history' => $this->whenLoaded('statusHistories', fn () => $this->statusHistories
                ->sortBy('id')
                ->map(fn ($history): array => [
                    'status' => $history->to_status,
                    'at' => $history->created_at,
                ])->values()->all()),
            'created_at' => $this->created_at,
        ];
    }
}
