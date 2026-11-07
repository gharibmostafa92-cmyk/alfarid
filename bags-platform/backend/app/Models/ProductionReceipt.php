<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inward stock receipt batch generated from internal manufacturing and bag assembly.
 */
class ProductionReceipt extends Model
{
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    protected $fillable = ['batch_number', 'branch_id', 'location_id', 'created_by', 'idempotency_key', 'request_hash', 'items', 'notes'];

    protected $hidden = ['idempotency_key', 'request_hash'];

    protected function casts(): array
    {
        return ['items' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('استلام الإنتاج مستند نهائي لا يُعدَّل.'));
        static::deleting(fn () => throw new \LogicException('استلام الإنتاج مستند نهائي لا يُحذف.'));
    }
}
