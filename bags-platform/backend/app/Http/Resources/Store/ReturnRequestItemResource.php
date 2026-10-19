<?php

declare(strict_types=1);

namespace App\Http\Resources\Store;

use App\Models\ReturnRequestItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReturnRequestItem
 */
class ReturnRequestItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_item_id' => $this->order_item_id,
            'product_variant_id' => $this->product_variant_id,
            'sku' => $this->whenLoaded('orderItem', fn () => $this->orderItem?->sku_snapshot),
            'product_name' => $this->whenLoaded('orderItem', fn () => $this->orderItem?->product_name_snapshot),
            'requested_qty' => $this->requested_qty,
            'approved_qty' => $this->approved_qty,
            'received_good_qty' => $this->received_good_qty,
            'received_damaged_qty' => $this->received_damaged_qty,
            'unit_refund_price' => $this->unit_refund_price,
            'item_reason' => $this->item_reason,
            'attachments' => array_map(
                fn (int $index): string => url('/api/v1/store/account/return-items/'.$this->id.'/attachments/'.$index),
                array_keys($this->attachments ?? []),
            ),
        ];
    }
}
