<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\SupplierPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SupplierPayment
 */
class SupplierPaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_number' => $this->payment_number,
            'supplier_id' => $this->supplier_id,
            'supplier_invoice_id' => $this->supplier_invoice_id,
            'invoice' => $this->whenLoaded('invoice', fn (): ?array => $this->invoice === null ? null : [
                'id' => $this->invoice->id,
                'invoice_number' => $this->invoice->invoice_number,
                'supplier_invoice_no' => $this->invoice->supplier_invoice_no,
            ]),
            'amount' => $this->amount,
            'payment_method' => $this->payment_method,
            'reference_no' => $this->reference_no,
            'payment_date' => $this->payment_date?->toDateString(),
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'voided_at' => $this->voided_at?->toISOString(),
            'voided_by' => $this->voided_by,
            'void_reason' => $this->void_reason,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
