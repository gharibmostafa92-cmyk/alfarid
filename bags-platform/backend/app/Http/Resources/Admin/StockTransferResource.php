<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockTransferResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transfer_number' => $this->transfer_number,
            'from_branch' => $this->whenLoaded('fromBranch', fn () => [
                'id' => $this->fromBranch->id,
                'name' => $this->fromBranch->name,
            ]),
            'to_branch' => $this->whenLoaded('toBranch', fn () => [
                'id' => $this->toBranch->id,
                'name' => $this->toBranch->name,
            ]),
            'status' => $this->status,
            'created_by' => $this->created_by,
            'creator_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'approved_by' => $this->approved_by,
            'shipped_at' => $this->shipped_at,
            'received_at' => $this->received_at,
            'notes' => $this->notes,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'product_variant_id' => $item->product_variant_id,
                'sku' => $item->relationLoaded('variant') ? $item->variant?->sku : null,
                'from_location' => $item->relationLoaded('fromLocation') && $item->fromLocation ? [
                    'id' => $item->fromLocation->id, 'name' => $item->fromLocation->name,
                ] : null,
                'to_location' => $item->relationLoaded('toLocation') && $item->toLocation ? [
                    'id' => $item->toLocation->id, 'name' => $item->toLocation->name,
                ] : null,
                'requested_qty' => $item->requested_qty,
                'approved_qty' => $item->approved_qty,
                'shipped_qty' => $item->shipped_qty,
                'received_qty' => $item->received_qty,
            ])->all()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
