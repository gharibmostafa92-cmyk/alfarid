<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SupplierDeliveryItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierDeliveryItem extends Model
{
    /** @use HasFactory<SupplierDeliveryItemFactory> */
    use HasFactory;

    protected $fillable = [
        'supplier_delivery_id',
        'purchase_order_item_id',
        'product_variant_id',
        'shipped_qty',
        'received_qty',
    ];

    protected function casts(): array
    {
        return [
            'shipped_qty' => 'decimal:4',
            'received_qty' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<SupplierDelivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(SupplierDelivery::class, 'supplier_delivery_id');
    }

    /** @return BelongsTo<PurchaseOrderItem, $this> */
    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
