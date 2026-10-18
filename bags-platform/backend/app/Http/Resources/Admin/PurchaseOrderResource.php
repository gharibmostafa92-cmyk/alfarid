<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
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
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->whenLoaded('supplier', fn (): ?array => $this->supplier === null ? null : [
                'id' => $this->supplier->id, 'code' => $this->supplier->code, 'name' => $this->supplier->name,
            ]),
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn (): ?array => $this->branch === null ? null : [
                'id' => $this->branch->id, 'name' => $this->branch->name,
            ]),
            'purchase_request_id' => $this->purchase_request_id,
            'status' => $this->status,
            'expected_delivery_date' => $this->expected_delivery_date?->toDateString(),
            'supplier_change_notes' => $this->supplier_change_notes,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'grand_total' => $this->grand_total,
            'created_by' => $this->created_by,
            'approved_by' => $this->approved_by,
            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')),
            'deliveries' => SupplierDeliveryResource::collection($this->whenLoaded('deliveries')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
