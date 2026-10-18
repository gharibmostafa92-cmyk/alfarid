<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseRequestItem>
 */
class PurchaseRequestItemFactory extends Factory
{
    protected $model = PurchaseRequestItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purchase_request_id' => PurchaseRequest::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'requested_qty' => 10,
            'notes' => null,
        ];
    }
}
