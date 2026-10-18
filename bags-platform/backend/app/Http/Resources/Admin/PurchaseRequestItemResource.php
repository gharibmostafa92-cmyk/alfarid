<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\PurchaseRequestItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseRequestItem
 */
class PurchaseRequestItemResource extends JsonResource
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
            ]),
            'requested_qty' => $this->requested_qty,
            'notes' => $this->notes,
        ];
    }
}
