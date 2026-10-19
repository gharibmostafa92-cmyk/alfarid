<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WholesalePaymentMethod;
use App\Enums\WholesalePaymentNoticeStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WholesalePaymentNotice extends Model
{
    use HasFactory;

    protected $fillable = [
        'notice_number',
        'customer_id',
        'order_id',
        'amount',
        'payment_method',
        'bank_name',
        'reference_number',
        'transfer_date',
        'receipt_file_path',
        'status',
        'reviewed_by',
        'admin_notes',
        'treasury_id',
        'treasury_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_method' => WholesalePaymentMethod::class,
            'transfer_date' => 'date',
            'status' => WholesalePaymentNoticeStatus::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function treasury(): BelongsTo
    {
        return $this->belongsTo(Treasury::class);
    }

    public function treasuryTransaction(): BelongsTo
    {
        return $this->belongsTo(TreasuryTransaction::class);
    }
}
