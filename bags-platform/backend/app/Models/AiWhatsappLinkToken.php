<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Short-lived single-use verification token for pairing a WhatsApp account with staff identity.
 */
class AiWhatsappLinkToken extends Model
{
    protected $fillable = [
        'identity_id',
        'token_hash',
        'expires_at',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(AiWhatsappIdentity::class, 'identity_id');
    }
}
