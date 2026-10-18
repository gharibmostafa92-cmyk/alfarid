<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\ReturnRequest;
use App\Models\ReturnRequestItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReturnRequestItem>
 */
class ReturnRequestItemFactory extends Factory
{
    protected $model = ReturnRequestItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'return_request_id' => ReturnRequest::factory(),
            'order_item_id' => OrderItem::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'requested_qty' => 1,
            'approved_qty' => 0,
            'received_good_qty' => 0,
            'received_damaged_qty' => 0,
            'unit_refund_price' => 100,
            'item_reason' => null,
            'attachments' => null,
        ];
    }
}
