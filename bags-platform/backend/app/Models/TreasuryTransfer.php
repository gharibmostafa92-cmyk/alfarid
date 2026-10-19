<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TreasuryTransferStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TreasuryTransfer extends Model
{
    protected $fillable = [
        'transfer_number',
        'from_treasury_id',
        'to_treasury_id',
        'amount',
        'status',
        'created_by',
        'approved_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => TreasuryTransferStatus::class,
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Treasury, $this> */
    public function fromTreasury(): BelongsTo
    {
        return $this->belongsTo(Treasury::class, 'from_treasury_id');
    }

    /** @return BelongsTo<Treasury, $this> */
    public function toTreasury(): BelongsTo
    {
        return $this->belongsTo(Treasury::class, 'to_treasury_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
