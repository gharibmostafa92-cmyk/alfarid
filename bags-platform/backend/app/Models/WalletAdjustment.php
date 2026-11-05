<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WalletAdjustment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'amount',
        'direction',
        'reason',
        'source',
        'created_by',
        'ai_action_id',
        'reverses_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<WalletAdjustment, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(WalletAdjustment::class, 'reverses_id');
    }

    /** @return HasMany<WalletAdjustment, $this> */
    public function reversals(): HasMany
    {
        return $this->hasMany(WalletAdjustment::class, 'reverses_id');
    }
}
