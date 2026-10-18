<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ReturnRequestItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** بند مرتجع: مطلوب ← معتمد ← مستلم (سليم/تالف) بأسعار رد مجمدة من لقطة الشراء. */
class ReturnRequestItem extends Model
{
    /** @use HasFactory<ReturnRequestItemFactory> */
    use HasFactory;

    protected $fillable = [
        'return_request_id',
        'order_item_id',
        'product_variant_id',
        'requested_qty',
        'approved_qty',
        'received_good_qty',
        'received_damaged_qty',
        'unit_refund_price',
        'item_reason',
        'attachments',
    ];

    protected function casts(): array
    {
        return [
            'requested_qty' => 'decimal:4',
            'approved_qty' => 'decimal:4',
            'received_good_qty' => 'decimal:4',
            'received_damaged_qty' => 'decimal:4',
            'unit_refund_price' => 'decimal:4',
            'attachments' => 'array',
        ];
    }

    /** @return BelongsTo<ReturnRequest, $this> */
    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
