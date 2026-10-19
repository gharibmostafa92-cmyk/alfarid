<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PosShiftStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosShift extends Model
{
    protected $fillable = [
        'shift_number',
        'user_id',
        'branch_id',
        'treasury_id',
        'status',
        'opened_at',
        'closed_at',
        'approved_at',
        'approved_by',
        'opening_cash',
        'cash_sales_total',
        'card_sales_total',
        'cash_returns_total',
        'cash_expenses_total',
        'expected_closing_cash',
        'actual_closing_cash',
        'cash_difference',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status'                => PosShiftStatus::class,
            'opened_at'             => 'datetime',
            'closed_at'             => 'datetime',
            'approved_at'           => 'datetime',
            'opening_cash'          => 'decimal:2',
            'cash_sales_total'      => 'decimal:2',
            'card_sales_total'      => 'decimal:2',
            'cash_returns_total'    => 'decimal:2',
            'cash_expenses_total'   => 'decimal:2',
            'expected_closing_cash' => 'decimal:2',
            'actual_closing_cash'   => 'decimal:2',
            'cash_difference'       => 'decimal:2',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Treasury, $this> */
    public function treasury(): BelongsTo
    {
        return $this->belongsTo(Treasury::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'pos_shift_id');
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'shift_id');
    }

    /** @return HasMany<TreasuryTransaction, $this> */
    public function treasuryTransactions(): HasMany
    {
        return $this->hasMany(TreasuryTransaction::class, 'shift_id');
    }
}
