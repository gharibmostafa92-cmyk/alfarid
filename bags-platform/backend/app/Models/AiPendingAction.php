<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['conversation_id', 'user_id', 'tool', 'args', 'expected_versions', 'preview', 'inverse', 'args_hash', 'risk', 'status', 'result', 'expires_at', 'executed_at'])]
class AiPendingAction extends Model
{
    use HasUlids;

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['args' => 'array', 'expected_versions' => 'array', 'preview' => 'array', 'inverse' => 'array', 'result' => 'array', 'expires_at' => 'datetime', 'executed_at' => 'datetime'];
    }
}
