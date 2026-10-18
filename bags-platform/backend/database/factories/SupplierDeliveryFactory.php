<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SupplierDeliveryStatus;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierDelivery>
 */
class SupplierDeliveryFactory extends Factory
{
    protected $model = SupplierDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'delivery_number' => 'DLV-'.str_pad((string) fake()->unique()->numberBetween(1001, 999999), 6, '0', STR_PAD_LEFT),
            'purchase_order_id' => PurchaseOrder::factory(),
            'supplier_id' => Supplier::factory(),
            'status' => SupplierDeliveryStatus::PendingReceipt,
            'delivery_date' => now()->toDateString(),
            'invoice_file_path' => null,
            'supplier_invoice_no' => null,
            'notes' => null,
        ];
    }
}
