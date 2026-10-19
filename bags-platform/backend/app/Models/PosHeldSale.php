<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosHeldSale extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'hold_reference',
        'branch_id',
        'user_id',
        'customer_id',
        'items_payload',
        'notes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'items_payload' => 'array',
            'created_at'    => 'datetime',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
