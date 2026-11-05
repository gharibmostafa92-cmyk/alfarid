<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiWhatsappInbound extends Model
{
    protected $table = 'ai_whatsapp_inbound';

    protected $fillable = [
        'wa_message_id',
        'identity_id',
        'conversation_id',
        'status',
        'ignore_reason',
    ];

    public function identity(): BelongsTo
    {
        return $this->belongsTo(AiWhatsappIdentity::class, 'identity_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}
