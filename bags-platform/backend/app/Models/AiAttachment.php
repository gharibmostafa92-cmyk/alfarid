<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAttachment extends Model
{
    use HasUlids;

    protected $fillable = [
        'conversation_id',
        'user_id',
        'path',
        'mime',
        'size',
        'sha256',
        'consumed_at',
    ];

    protected $casts = [
        'size' => 'integer',
        'consumed_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
