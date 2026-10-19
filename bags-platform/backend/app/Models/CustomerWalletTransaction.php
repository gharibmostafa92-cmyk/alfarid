<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerWalletTransaction extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'balance_after' => 'decimal:2', 'created_at' => 'datetime'];
    }
}
