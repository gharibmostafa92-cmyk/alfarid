<?php

declare(strict_types=1);

namespace App\Http\Resources\Supplier;

use App\Models\SupplierDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SupplierDelivery
 */
class DeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'delivery_number' => $this->delivery_number,
            'purchase_order_id' => $this->purchase_order_id,
            'status' => $this->status,
            'delivery_date' => $this->delivery_date?->toDateString(),
            'invoice_file_url' => $this->invoice_file_path ? url('/api/v1/supplier/deliveries/'.$this->id.'/invoice') : null,
            'supplier_invoice_no' => $this->supplier_invoice_no,
            'notes' => $this->notes,
            'items' => DeliveryItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
