<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WholesalePaymentMethod;
use App\Enums\WholesalePaymentNoticeStatus;
use App\Models\Customer;
use App\Models\WholesalePaymentNotice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WholesalePaymentNotice> */
class WholesalePaymentNoticeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'notice_number' => 'WPN-'.fake()->unique()->numerify('######'),
            'customer_id' => Customer::factory(),
            'order_id' => null,
            'amount' => 500,
            'payment_method' => WholesalePaymentMethod::BankTransfer,
            'bank_name' => 'بنك تجريبي',
            'reference_number' => fake()->numerify('REF-######'),
            'transfer_date' => now()->toDateString(),
            'receipt_file_path' => 'wholesale_payment_notices/test-receipt.jpg',
            'status' => WholesalePaymentNoticeStatus::PendingReview,
        ];
    }
}
