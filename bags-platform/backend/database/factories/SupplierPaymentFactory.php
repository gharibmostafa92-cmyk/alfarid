<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SupplierPaymentMethod;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierPayment>
 */
class SupplierPaymentFactory extends Factory
{
    protected $model = SupplierPayment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_number' => 'SPY-'.str_pad((string) fake()->unique()->numberBetween(1001, 999999), 6, '0', STR_PAD_LEFT),
            'supplier_id' => Supplier::factory(),
            'supplier_invoice_id' => null,
            'amount' => 500,
            'payment_method' => SupplierPaymentMethod::BankTransfer,
            'reference_no' => null,
            'payment_date' => now()->toDateString(),
            'notes' => null,
            'created_by' => User::factory(),
        ];
    }
}
