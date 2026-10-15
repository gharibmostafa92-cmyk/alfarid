<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PriceListResource extends JsonResource
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
            'name' => $this->name,
            'type' => $this->type,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => $this->customer->id, 'name' => $this->customer->name,
            ] : null),
            'customer_group' => $this->whenLoaded('customerGroup', fn () => $this->customerGroup ? [
                'id' => $this->customerGroup->id, 'name' => $this->customerGroup->name, 'code' => $this->customerGroup->code,
            ] : null),
            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? [
                'id' => $this->branch->id, 'name' => $this->branch->name,
            ] : null),
            'currency' => $this->currency,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'priority' => $this->priority,
            'is_active' => $this->is_active,
            'items_count' => $this->whenCounted('items'),
            'created_at' => $this->created_at,
        ];
    }
}
