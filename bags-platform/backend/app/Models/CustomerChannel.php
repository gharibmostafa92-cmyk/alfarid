<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracks third-party messaging channels (such as WhatsApp Business) linked to customer accounts.
 */
class CustomerChannel extends Model
{
    protected $fillable = [
        'customer_id',
        'phone',
        'provider',
        'verification_status',
        'opt_in_at',
        'verified_at',
        'verification_code_hash',
        'verification_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'opt_in_at' => 'datetime',
            'verified_at' => 'datetime',
            'verification_expires_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
