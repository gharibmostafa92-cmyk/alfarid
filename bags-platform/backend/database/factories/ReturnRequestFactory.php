<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReturnStatus;
use App\Models\Order;
use App\Models\ReturnRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReturnRequest>
 */
class ReturnRequestFactory extends Factory
{
    protected $model = ReturnRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'return_number' => 'RET-'.str_pad((string) fake()->unique()->numberBetween(1001, 999999), 6, '0', STR_PAD_LEFT),
            'order_id' => Order::factory(),
            'customer_id' => null,
            'branch_id' => null,
            'status' => ReturnStatus::Pending,
            'refund_method' => null,
            'total_refund_amount' => 0,
            'reason' => 'المنتج لا يناسبني',
        ];
    }
}
