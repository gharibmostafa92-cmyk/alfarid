<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['conversation_id', 'role', 'content', 'ui_blocks', 'provider_parts', 'input_tokens', 'output_tokens'])]
class AiMessage extends Model
{
    protected $hidden = ['provider_parts'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    protected function casts(): array
    {
        return ['ui_blocks' => 'array', 'provider_parts' => 'array', 'input_tokens' => 'integer', 'output_tokens' => 'integer'];
    }
}
