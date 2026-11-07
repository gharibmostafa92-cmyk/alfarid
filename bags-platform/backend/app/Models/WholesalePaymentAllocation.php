<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Settlement link distributing approved wholesale payments against outstanding orders.
 */
class WholesalePaymentAllocation extends Model
{
    protected $fillable = [
        'notice_id',
        'order_id',
        'amount',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function notice(): BelongsTo
    {
        return $this->belongsTo(WholesalePaymentNotice::class, 'notice_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
