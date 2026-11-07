<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Approved message templates and variable mappings for external channel messaging.
 */
class MessageTemplate extends Model
{
    protected $fillable = [
        'name',
        'language',
        'body',
        'variables',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
        ];
    }
}
