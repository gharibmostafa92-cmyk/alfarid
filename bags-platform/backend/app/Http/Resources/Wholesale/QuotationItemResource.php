<?php

declare(strict_types=1);

namespace App\Http\Resources\Wholesale;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuotationItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_variant_id' => $this->product_variant_id,
            'variant' => $this->whenLoaded('variant', fn () => [
                'id' => $this->variant->id,
                'sku' => $this->variant->sku,
                'product_name' => $this->variant->product?->name,
            ]),
            'qty' => $this->qty,
            'requested_target_price' => $this->requested_target_price,
            'offered_unit_price' => $this->offered_unit_price,
            'line_total' => $this->line_total,
        ];
    }
}
