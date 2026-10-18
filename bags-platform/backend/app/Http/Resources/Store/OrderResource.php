<?php

declare(strict_types=1);

namespace App\Http\Resources\Store;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** تمثيل طلب للعميل (إتمام شراء / طلبات الحساب). */
class OrderResource extends JsonResource
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
            'recipient_name' => $this->guest_name,
            'recipient_phone' => $this->guest_phone,
            'city_id' => $this->city_id,
            'shipping_address_line' => $this->shipping_address_line,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'coupon_code' => $this->coupon_code,
            'shipping_cost' => $this->shipping_cost,
            // رسوم الدفع عند الاستلام جزء من الإجمالي (M4): بغير عرضها يظهر
            // للعميل subtotal − discount + shipping + tax ≠ grand_total
            'cod_fee' => $this->cod_fee,
            'tax_amount' => $this->tax_amount,
            'grand_total' => $this->grand_total,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'id' => $item->id,
                'product_variant_id' => $item->product_variant_id,
                'product_name' => $item->product_name_snapshot,
                'sku' => $item->sku_snapshot,
                'qty' => $item->qty,
                'unit_price' => $item->unit_price,
                'discount_amount' => $item->discount_amount,
                'line_total' => $item->line_total,
            ])->all()),
            'created_at' => $this->created_at,
        ];
    }
}
