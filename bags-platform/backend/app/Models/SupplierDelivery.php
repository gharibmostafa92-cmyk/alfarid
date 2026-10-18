<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SupplierDeliveryStatus;
use Database\Factories\SupplierDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** إشعار توريد (ASN) يرسله المورد من بوابته مقابل أمر شراء. */
class SupplierDelivery extends Model
{
    /** @use HasFactory<SupplierDeliveryFactory> */
    use HasFactory;

    protected $fillable = [
        'delivery_number',
        'purchase_order_id',
        'supplier_id',
        'status',
        'delivery_date',
        'invoice_file_path',
        'supplier_invoice_no',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => SupplierDeliveryStatus::class,
            'delivery_date' => 'date',
        ];
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return HasMany<SupplierDeliveryItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SupplierDeliveryItem::class);
    }
}
