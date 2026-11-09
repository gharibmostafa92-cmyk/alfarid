<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Wholesale\WholesalePaymentMethod;
use App\Enums\Wholesale\WholesalePaymentNoticeStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Wholesale customer bank transfer payment notice with OCR match verification, receipt attachment, and treasury link.
 */
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
        'approved_at',
        'admin_notes',
        'receipt_sha256',
        'ocr_payload',
        'match_score',
        'treasury_id',
        'treasury_transaction_id',
        'reversed_at',
        'reversal_treasury_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_method' => WholesalePaymentMethod::class,
            'transfer_date' => 'date',
            'approved_at' => 'datetime',
            'reversed_at' => 'datetime',
            'ocr_payload' => 'array',
            'match_score' => 'decimal:2',
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

    public function allocations(): HasMany
    {
        return $this->hasMany(WholesalePaymentAllocation::class, 'notice_id');
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

    /**
     * Scope query to match reference number case-insensitively with trimmed whitespace.
     */
    public function scopeWhereReferenceNumber($query, string $reference)
    {
        return $query->whereRaw('lower(btrim(reference_number)) = ?', [mb_strtolower(trim($reference))]);
    }
}
