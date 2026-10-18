<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\ReturnRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReturnRequest
 */
class ReturnRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'return_number' => $this->return_number,
            'order_id' => $this->order_id,
            'order_number' => $this->whenLoaded('order', fn () => $this->order?->order_number),
            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer?->name),
            'branch_id' => $this->branch_id,
            'status' => $this->status->value,
            'refund_method' => $this->refund_method?->value,
            'total_refund_amount' => $this->total_refund_amount,
            'reason' => $this->reason,
            'admin_notes' => $this->admin_notes,
            'refund_reference' => $this->refund_reference,
            'approved_by' => $this->approved_by,
            'received_by' => $this->received_by,
            'refunded_by' => $this->refunded_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'refunded_at' => $this->refunded_at?->toIso8601String(),
            'items' => ReturnRequestItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
