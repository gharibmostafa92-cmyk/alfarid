<?php

declare(strict_types=1);

namespace App\Http\Resources\Supplier;

use App\Models\SupplierDeliveryItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SupplierDeliveryItem
 */
class DeliveryItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_order_item_id' => $this->purchase_order_item_id,
            'product_variant_id' => $this->product_variant_id,
            'variant' => $this->whenLoaded('variant', fn (): ?array => $this->variant === null ? null : [
                'id' => $this->variant->id,
                'sku' => $this->variant->sku,
            ]),
            'shipped_qty' => $this->shipped_qty,
            'received_qty' => $this->received_qty,
        ];
    }
}
