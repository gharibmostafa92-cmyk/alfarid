<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Durable per-message delivery record for WhatsApp replies.
 */
class AiWhatsappOutbound extends Model
{
    protected $table = 'ai_whatsapp_outbound';

    protected $fillable = [
        'inbound_id',
        'sequence',
        'wa_message_id',
        'delivery_status',
        'recipient_jid',
        'body',
        'conversation_id',
        'pending_action_id',
        'picker',
    ];

    protected function casts(): array
    {
        return ['picker' => 'array'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}
