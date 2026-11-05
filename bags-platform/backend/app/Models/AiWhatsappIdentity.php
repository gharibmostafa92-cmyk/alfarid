<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiWhatsappIdentity extends Model
{
    protected $fillable = [
        'user_id',
        'phone_e164',
        'wa_jid',
        'wa_lid',
        'status',
        'verified_at',
        'pin_hash',
        'unlocked_until',
        'last_inbound_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'unlocked_until' => 'datetime',
            'last_inbound_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function linkTokens(): HasMany
    {
        return $this->hasMany(AiWhatsappLinkToken::class, 'identity_id');
    }
}
