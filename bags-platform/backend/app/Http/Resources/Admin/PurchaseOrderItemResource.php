<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\PurchaseOrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseOrderItem
 */
class PurchaseOrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_variant_id' => $this->product_variant_id,
            'variant' => $this->whenLoaded('variant', fn (): ?array => $this->variant === null ? null : [
                'id' => $this->variant->id,
                'sku' => $this->variant->sku,
                'cost_price' => $this->variant->cost_price,
                'last_cost_price' => $this->variant->last_cost_price,
            ]),
            'ordered_qty' => $this->ordered_qty,
            'received_qty' => $this->received_qty,
            'unit_cost' => $this->unit_cost,
            'tax_rate' => $this->tax_rate,
            'line_total' => $this->line_total,
        ];
    }
}
