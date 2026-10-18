<?php

declare(strict_types=1);

namespace App\Http\Resources\Supplier;

use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * أمر شراء كما يراه المورد في بوابته (بلا حقول الإدارة الداخلية).
 *
 * @mixin PurchaseOrder
 */
class PurchaseOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'po_number' => $this->po_number,
            'status' => $this->status,
            'expected_delivery_date' => $this->expected_delivery_date?->toDateString(),
            'supplier_change_notes' => $this->supplier_change_notes,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'grand_total' => $this->grand_total,
            'branch' => $this->whenLoaded('branch', fn (): ?array => $this->branch === null ? null : [
                'id' => $this->branch->id, 'name' => $this->branch->name,
            ]),
            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')),
            'deliveries' => DeliveryResource::collection($this->whenLoaded('deliveries')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
