<?php

declare(strict_types=1);

namespace App\Http\Resources\Store;

use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-facing order projection for checkout confirmation and order history views.
 */
class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
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
            // Expose COD collection fee so itemized charges reconcile with grand_total.
            'cod_fee' => $this->cod_fee,
            'tax_amount' => $this->tax_amount,
            'grand_total' => $this->grand_total,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'id' => $item->id,
                'product_variant_id' => $item->product_variant_id,
                'product_name' => $item->product_name_snapshot,
                'image' => $this->itemImage($item),
                'sku' => $item->sku_snapshot,
                'qty' => $item->qty,
                'unit_price' => $item->unit_price,
                'discount_amount' => $item->discount_amount,
                'line_total' => $item->line_total,
            ])->all()),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Resolve the display image URL, falling back from variant to parent product gallery.
     */
    private function itemImage(OrderItem $item): ?string
    {
        $variant = $item->variant;
        // Variants without custom color photos inherit their parent product's primary image.
        $image = $variant?->getFirstMedia('images') ?? $variant?->product?->getFirstMedia('images');

        return $image?->getUrl();
    }
}
