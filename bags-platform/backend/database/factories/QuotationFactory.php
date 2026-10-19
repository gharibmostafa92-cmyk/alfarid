<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\QuotationStatus;
use App\Models\Customer;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Quotation> */
class QuotationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'quotation_number' => 'QT-'.fake()->unique()->numerify('######'),
            'customer_id' => Customer::factory(),
            'status' => QuotationStatus::Draft,
            'valid_until' => null,
            'subtotal' => 0,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'grand_total' => 0,
        ];
    }
}
