<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAuditLog extends Model
{
    protected $guarded = [];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public static function record(?Customer $customer, string $action, ?int $actorId, array $metadata = []): void
    {
        self::query()->create(['customer_id' => $customer?->id, 'actor_id' => $actorId, 'action' => $action, 'metadata' => $metadata]);
    }
}
