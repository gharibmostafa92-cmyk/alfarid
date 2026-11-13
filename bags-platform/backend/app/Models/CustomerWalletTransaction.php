<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ledger entry for customer store credit balances, deposits, and order settlements.
 */
class CustomerWalletTransaction extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'type',
        'amount',
        'balance_after',
        'reference_type',
        'reference_id',
        'created_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'balance_after' => 'decimal:2', 'created_at' => 'datetime'];
    }
}
