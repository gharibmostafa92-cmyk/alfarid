<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['user_id', 'title', 'model', 'total_tokens', 'last_message_at'])]
class AiConversation extends Model
{
    use SoftDeletes;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'conversation_id');
    }

    public function pendingActions(): HasMany
    {
        return $this->hasMany(AiPendingAction::class, 'conversation_id');
    }

    protected function casts(): array
    {
        return ['total_tokens' => 'integer', 'last_message_at' => 'datetime'];
    }
}
