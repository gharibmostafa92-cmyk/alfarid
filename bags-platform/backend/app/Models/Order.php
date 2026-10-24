<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use App\Models\PosShift;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * طلب. أي تغيير في status يجب أن يمر عبر OrderService::changeStatus()
 * حصرًا — هي التي تسجل التغيير في order_status_histories وتطلق الحجز
 * والتوزيع عند الانتقال إلى confirmed.
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'order_number',
        'channel',
        'customer_id',
        'guest_name',
        'guest_phone',
        'guest_phone_normalized',
        'guest_email',
        'city_id',
        'shipping_address_line',
        'shipping_notes',
        'allocated_branch_id',
        'pos_shift_id',
        'return_of_order_id',
        'status',
        'payment_method',
        'payment_status',
        'requires_refund',
        'needs_attention',
        'cod_fee',
        'idempotency_key',
        'idempotency_scope',
        'gateway_order_id',
        'subtotal',
        'discount_amount',
        'coupon_id',
        'coupon_code',
        'shipping_cost',
        'tax_amount',
        'grand_total',
        'cash_amount',
        'card_amount',
    ];

    protected function casts(): array
    {
        return [
            'status'          => OrderStatus::class,
            'subtotal'        => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'shipping_cost'   => 'decimal:2',
            'cod_fee'         => 'decimal:2',
            'tax_amount'      => 'decimal:2',
            'grand_total'     => 'decimal:2',
            'cash_amount'     => 'decimal:2',
            'card_amount'     => 'decimal:2',
            'requires_refund' => 'boolean',
            'needs_attention' => 'boolean',
        ];
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\PosShift, $this> */
    public function posShift(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PosShift::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->latest('id');
    }

    /** @return HasMany<Shipment, $this> */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    /** @return HasMany<ReturnRequest, $this> */
    public function returnRequests(): HasMany
    {
        return $this->hasMany(ReturnRequest::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function allocatedBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'allocated_branch_id');
    }
}
