<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShippingCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shipment>
 */
class ShipmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'shipping_company_id' => ShippingCompany::factory(),
            'awb_number' => 'AWB-'.fake()->unique()->numerify('########'),
            'status' => ShipmentStatus::Created,
            'label_url' => null,
            'shipping_cost' => 50,
            'cod_amount' => 0,
            'raw_response' => null,
        ];
    }
}
