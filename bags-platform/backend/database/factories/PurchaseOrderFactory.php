<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PurchaseOrderStatus;
use App\Models\Branch;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'po_number' => 'PO-'.str_pad((string) fake()->unique()->numberBetween(1000, 999999), 6, '0', STR_PAD_LEFT),
            'supplier_id' => Supplier::factory(),
            'branch_id' => Branch::factory(),
            'purchase_request_id' => null,
            'status' => PurchaseOrderStatus::Draft,
            'expected_delivery_date' => now()->addDays(7)->toDateString(),
            'supplier_change_notes' => null,
            'subtotal' => 0,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'grand_total' => 0,
            'created_by' => User::factory(),
            'approved_by' => null,
        ];
    }
}
