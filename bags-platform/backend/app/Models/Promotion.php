<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PromotionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    /** @use HasFactory<PromotionFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'value',
        'min_order_amount',
        'applies_to',
        'target_ids',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'min_order_amount' => 'decimal:2',
            'target_ids' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
