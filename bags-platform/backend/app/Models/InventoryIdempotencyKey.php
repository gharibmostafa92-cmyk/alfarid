<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Idempotency key tracking for manual stock adjustments; created and consumed within InventoryService.
 */
class InventoryIdempotencyKey extends Model
{
    protected $fillable = ['user_id', 'key', 'request_hash', 'response', 'status_code'];

    protected function casts(): array
    {
        return [
            'response' => 'array',
            'status_code' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
