<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\SupplierInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SupplierInvoice
 */
class SupplierInvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'supplier_invoice_no' => $this->supplier_invoice_no,
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->whenLoaded('supplier', fn (): ?array => $this->supplier === null ? null : [
                'id' => $this->supplier->id, 'code' => $this->supplier->code, 'name' => $this->supplier->name,
            ]),
            'purchase_order_id' => $this->purchase_order_id,
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn (): ?array => $this->purchaseOrder === null ? null : [
                'id' => $this->purchaseOrder->id, 'po_number' => $this->purchaseOrder->po_number,
            ]),
            'issue_date' => $this->issue_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
            'paid_amount' => $this->paid_amount,
            'remaining_amount' => number_format((float) $this->total_amount - (float) $this->paid_amount, 2, '.', ''),
            'status' => $this->status,
            'attachment_url' => $this->attachment_path ? url('/api/v1/admin/supplier-invoices/'.$this->id.'/attachment') : null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
