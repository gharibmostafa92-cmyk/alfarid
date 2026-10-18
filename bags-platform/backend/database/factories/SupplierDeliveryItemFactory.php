<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\PurchaseOrderItem;
use App\Models\SupplierDelivery;
use App\Models\SupplierDeliveryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierDeliveryItem>
 */
class SupplierDeliveryItemFactory extends Factory
{
    protected $model = SupplierDeliveryItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_delivery_id' => SupplierDelivery::factory(),
            'purchase_order_item_id' => PurchaseOrderItem::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'shipped_qty' => 5,
            'received_qty' => 0,
        ];
    }
}
