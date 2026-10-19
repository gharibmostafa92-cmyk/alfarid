<?php

declare(strict_types=1);

namespace App\Http\Resources\Wholesale;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WholesalePaymentNoticeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'notice_number' => $this->notice_number,
            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'company_name' => $this->customer->company_name,
            ]),
            'order_id' => $this->order_id,
            'order_number' => $this->whenLoaded('order', fn () => $this->order?->order_number),
            'amount' => $this->amount,
            'payment_method' => $this->payment_method->value,
            'bank_name' => $this->bank_name,
            'reference_number' => $this->reference_number,
            'transfer_date' => $this->transfer_date?->toDateString(),
            'has_receipt' => $this->receipt_file_path !== null,
            'status' => $this->status->value,
            'reviewed_by' => $this->reviewed_by,
            'admin_notes' => $this->admin_notes,
            'treasury_id' => $this->treasury_id,
            'treasury_transaction_id' => $this->treasury_transaction_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
