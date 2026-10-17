<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShipmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'order_number' => $this->whenLoaded('order', fn () => $this->order?->order_number),
            'shipping_company_id' => $this->shipping_company_id,
            'company_name' => $this->whenLoaded('company', fn () => $this->company?->name),
            'awb_number' => $this->awb_number,
            'status' => $this->status,
            'tracking_url' => $this->resource->trackingUrl(),
            'label_url' => $this->label_url,
            'shipping_cost' => $this->shipping_cost,
            'cod_amount' => $this->cod_amount,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
