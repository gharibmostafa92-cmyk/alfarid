<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransferStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    protected $attributes = ['version' => 1];

    protected static function booted(): void
    {
        static::saving(function (self $transfer): void {
            if (! $transfer->exists) {
                return;
            }
            $transfer->version = (int) $transfer->getRawOriginal('version') + 1;
            $previous = Carbon::parse($transfer->getRawOriginal('updated_at'));
            $current = now()->startOfSecond();
            $transfer->updated_at = $current->greaterThan($previous) ? $current : $previous->addSecond();
        });
    }

    protected $fillable = [
        'transfer_number', 'from_branch_id', 'to_branch_id', 'status',
        'created_by', 'approved_by', 'shipped_at', 'received_at', 'notes',
    ];

    protected $casts = [
        'version' => 'integer',
        'status' => TransferStatus::class,
        'shipped_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
