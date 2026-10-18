<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SupplierInvoiceStatus;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierInvoice>
 */
class SupplierInvoiceFactory extends Factory
{
    protected $model = SupplierInvoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = 1000;

        return [
            'invoice_number' => 'SIN-'.str_pad((string) fake()->unique()->numberBetween(1001, 999999), 6, '0', STR_PAD_LEFT),
            'supplier_invoice_no' => 'PAPER-'.fake()->unique()->numberBetween(1000, 99999),
            'supplier_id' => Supplier::factory(),
            'purchase_order_id' => null,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal' => $subtotal,
            'tax_amount' => 140,
            'total_amount' => $subtotal + 140,
            'paid_amount' => 0,
            'status' => SupplierInvoiceStatus::Draft,
            'attachment_path' => null,
        ];
    }
}
