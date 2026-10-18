<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrderItem>
 */
class PurchaseOrderItemFactory extends Factory
{
    protected $model = PurchaseOrderItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $qty = 10;
        $cost = 50;

        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'ordered_qty' => $qty,
            'received_qty' => 0,
            'unit_cost' => $cost,
            'tax_rate' => 14,
            'line_total' => $qty * $cost,
        ];
    }
}
