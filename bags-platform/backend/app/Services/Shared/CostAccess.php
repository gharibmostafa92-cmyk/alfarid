<?php

declare(strict_types=1);

namespace App\Services\Shared;

use App\Models\User;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use JsonSerializable;
use stdClass;
use Throwable;

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
    public const DEFAULT_KEYS = [
        'cost_price', 'cost', 'unit_cost', 'total_cost', 'avg_cost', 'average_cost', 'last_cost_price',
        // M8: derived fields reveal cost arithmetically (price - margin = cost).
        'margin', 'profit', 'inventory_value', 'purchase_price',
    ];

    /**
     * Recursively remove confidential unit cost keys from data payloads.
     *
     * Nested Eloquent models, collections, JsonResource instances, and other
     * Arrayable values are normalized first so cost attributes cannot slip
     * through as objects. Resources resolve against the current HTTP request
     * when one is bound, otherwise against a blank request.
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
                $value = self::resolveResource($value);
            }
            // DB::table() rows and DTOs are not Arrayable; normalize them so
            // confidential keys cannot slip through as object properties.
            if ($value instanceof Arrayable) {
                $value = $value->toArray();
            } elseif ($value instanceof JsonSerializable) {
                $value = $value->jsonSerialize();
            }
            if ($value instanceof stdClass) {
                $value = (array) $value;
            }
            $out[$key] = is_array($value) ? self::strip($value, $keys) : $value;
        }

        return $out;
    }

    /**
     * Resolve a nested resource to a plain array for cost filtering.
     *
     * @return array<string,mixed>
     */
    private static function resolveResource(JsonResource $resource): array
    {
        try {
            $request = app('request');
        } catch (Throwable) {
            $request = null;
        }
        if (! $request instanceof Request) {
            // Queue/console context: a blank request keeps toArray(Request)
            // satisfiable; its default user resolver yields null.
            $request = new Request;
        }

        return $resource->resolve($request);
    }
}
