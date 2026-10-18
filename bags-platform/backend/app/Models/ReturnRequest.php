<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RefundMethod;
use App\Enums\ReturnStatus;
use Database\Factories\ReturnRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * طلب مرتجع (RMA). دورة حياته وآثاره المخزنية والمالية كلها في
 * ReturnService — القاعدة الذهبية: الإنشاء والموافقة المبدئية لا يحرّكان
 * رصيدًا؛ الإضافة الفعلية للمخزون تحدث عند الاستلام والفحص فقط.
 */
class ReturnRequest extends Model
{
    /** @use HasFactory<ReturnRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'return_number',
        'order_id',
        'customer_id',
        'branch_id',
        'status',
        'refund_method',
        'total_refund_amount',
        'reason',
        'admin_notes',
        'refund_reference',
        'approved_by',
        'received_by',
        'refunded_by',
        'approved_at',
        'received_at',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReturnStatus::class,
            'refund_method' => RefundMethod::class,
            'total_refund_amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'received_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<ReturnRequestItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ReturnRequestItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** @return BelongsTo<User, $this> */
    public function refunder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }
}
