<?php

declare(strict_types=1);

namespace App\Services\Shared;

use App\Models\User;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Authorizes access to confidential product and inventory unit cost values.
 * Both legacy permissions represent the same commercial data boundary.
 */
final class CostAccess
{
    /**
     * Check if the authenticated staff user has permission to view unit costs.
     */
    public static function canView(?User $user): bool
    {
        return $user !== null && ($user->can('products.view_cost') || $user->can('inventory.view_cost'));
    }

    /**
     * Default confidential cost keys stripped when callers pass no explicit list.
     *
     * @var list<string>
     */
    public const DEFAULT_KEYS = ['cost_price', 'cost', 'unit_cost', 'total_cost', 'avg_cost', 'average_cost'];

    /**
     * Recursively remove confidential unit cost keys from data payloads.
     *
     * Nested Eloquent models, collections, and other Arrayable values are
     * normalized via toArray() first so cost attributes cannot slip through
     * as objects (JsonResource is left untouched — it needs request context).
     *
     * @param  array<string,mixed>  $payload
     * @param  list<string>|null  $keys  Explicit key list; defaults to DEFAULT_KEYS.
     * @return array<string,mixed>
     */
    public static function strip(array $payload, ?array $keys = null): array
    {
        $keys ??= self::DEFAULT_KEYS;
        $out = [];
        foreach ($payload as $key => $value) {
            if (in_array($key, $keys, true)) {
                continue;
            }
            if ($value instanceof JsonResource) {
                $out[$key] = $value;
                continue;
            }
            if ($value instanceof Arrayable) {
                $value = $value->toArray();
            }
            $out[$key] = is_array($value) ? self::strip($value, $keys) : $value;
        }

        return $out;
    }
}
