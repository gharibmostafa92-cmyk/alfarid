<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Finance\TreasuryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Cash drawer, bank account, or safe representing a monetary fund repository.
 */
class Treasury extends Model
{
    protected $fillable = [
        'name',
        'code',
        'branch_id',
        'type',
        'current_balance',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type'            => TreasuryType::class,
            'current_balance' => 'decimal:2',
            'is_active'       => 'boolean',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<TreasuryTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(TreasuryTransaction::class);
    }

    /** @return HasOne<TreasuryTransaction, $this> */
    public function latestTransaction(): HasOne
    {
        return $this->hasOne(TreasuryTransaction::class)->latestOfMany('id');
    }

    /** @return HasMany<PosShift, $this> */
    public function shifts(): HasMany
    {
        return $this->hasMany(PosShift::class);
    }
}
