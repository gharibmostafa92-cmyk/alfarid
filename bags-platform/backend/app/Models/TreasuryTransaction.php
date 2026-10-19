<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TreasuryTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TreasuryTransaction extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'treasury_id',
        'shift_id',
        'type',
        'amount',
        'balance_after',
        'reference_type',
        'reference_id',
        'created_by',
        'notes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type'          => TreasuryTransactionType::class,
            'amount'        => 'decimal:2',
            'balance_after' => 'decimal:2',
            'created_at'    => 'datetime',
        ];
    }

    /** @return BelongsTo<Treasury, $this> */
    public function treasury(): BelongsTo
    {
        return $this->belongsTo(Treasury::class);
    }

    /** @return BelongsTo<PosShift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(PosShift::class);
    }

    /** @return MorphTo<Model, $this> */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
