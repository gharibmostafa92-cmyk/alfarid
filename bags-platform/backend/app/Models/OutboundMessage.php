<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stores queued, dispatched, and shadowed outbound notifications with idempotency protection.
 */
class OutboundMessage extends Model
{
    protected $fillable = [
        'customer_id',
        'channel',
        'idempotency_key',
        'status',
        'template',
        'payload',
        'provider_message_id',
        'error',
        'scheduled_for',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'scheduled_for' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
