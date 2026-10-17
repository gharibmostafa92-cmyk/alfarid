<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShippingRateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shipping_company_id' => $this->shipping_company_id,
            'company_name' => $this->whenLoaded('company', fn () => $this->company?->name),
            'shipping_zone_id' => $this->shipping_zone_id,
            'zone_name' => $this->whenLoaded('zone', fn () => $this->zone?->name),
            'base_cost' => $this->base_cost,
            'free_shipping_threshold' => $this->free_shipping_threshold,
            'cod_fee' => $this->cod_fee,
            'estimated_days_min' => $this->estimated_days_min,
            'estimated_days_max' => $this->estimated_days_max,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
