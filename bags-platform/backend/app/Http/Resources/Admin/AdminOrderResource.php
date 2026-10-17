<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** تمثيل طلب للإدارة (قائمة وتفاصيل). */
class AdminOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'channel' => $this->channel,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer === null ? null : [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
                'type' => $this->customer->type,
            ]),
            'guest_name' => $this->guest_name,
            'guest_phone' => $this->guest_phone,
            'guest_email' => $this->guest_email,
            'city' => $this->whenLoaded('city', fn () => $this->city === null ? null : [
                'id' => $this->city->id,
                'name_ar' => $this->city->name_ar,
            ]),
            'shipping_address_line' => $this->shipping_address_line,
            'shipping_notes' => $this->shipping_notes,
            'allocated_branch' => $this->whenLoaded('allocatedBranch', fn () => $this->allocatedBranch === null ? null : [
                'id' => $this->allocatedBranch->id,
                'name' => $this->allocatedBranch->name,
            ]),
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'coupon_code' => $this->coupon_code,
            'shipping_cost' => $this->shipping_cost,
            'tax_amount' => $this->tax_amount,
            'grand_total' => $this->grand_total,
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'id' => $item->id,
                'product_variant_id' => $item->product_variant_id,
                'product_name' => $item->product_name_snapshot,
                'sku' => $item->sku_snapshot,
                'allocated_branch_id' => $item->allocated_branch_id,
                'qty' => $item->qty,
                'unit_price' => $item->unit_price,
                'discount_amount' => $item->discount_amount,
                'tax_amount' => $item->tax_amount,
                'line_total' => $item->line_total,
            ])->all()),
            'status_history' => $this->whenLoaded('statusHistories', fn () => $this->statusHistories
                ->sortBy('id')
                ->map(fn ($history): array => [
                    'from_status' => $history->from_status,
                    'to_status' => $history->to_status,
                    'changed_by_type' => $history->changed_by_type,
                    'changed_by_id' => $history->changed_by_id,
                    'notes' => $history->notes,
                    'at' => $history->created_at,
                ])->values()->all()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
