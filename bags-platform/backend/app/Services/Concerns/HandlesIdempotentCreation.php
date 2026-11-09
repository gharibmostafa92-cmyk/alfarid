<?php

declare(strict_types=1);

namespace App\Services\Concerns;

use App\Exceptions\BusinessException;
use App\Models\InventoryIdempotencyKey;
use App\Services\Inventory\BranchAccess;
use App\Services\Shared\IdempotencyFingerprint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Handles idempotent entity creation (such as stock transfers and stocktakes).
 * Replaying a request with the same idempotency key and payload hash returns the previously created model.
 * The idempotency record is persisted within the creation transaction so rollback discards the key as well.
 */
trait HandlesIdempotentCreation
{
    /**
     * Execute the creation callback idempotently under the supplied key.
     *
     * @param  list<string>  $relations
     * @param  callable(): Model  $creator
     */
    private function createIdempotently(string $context, array $payload, int $userId, ?string $idempotencyKey, string $modelClass, array $relations, callable $creator): Model
    {
        if ($idempotencyKey === null) {
            return $creator();
        }

        $requestHash = IdempotencyFingerprint::hash(['__context' => $context, 'payload' => $payload]);

        try {
            return DB::transaction(function () use ($userId, $idempotencyKey, $requestHash, $creator): Model {
                $record = InventoryIdempotencyKey::query()->create([
                    'user_id' => $userId,
                    'key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                ]);

                $model = $creator();

                $record->update(['response' => [$model->id], 'status_code' => 201]);

                return $model;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Extract constraint name from driver-specific errorInfo to reliably detect idempotency key collisions.
            $driverMessage = (string) ($e->errorInfo[2] ?? $e->getMessage());
            if (! str_contains($driverMessage, 'inventory_idempotency_keys_user_id_key_unique')
                && ! str_contains($e->getMessage(), 'inventory_idempotency_keys_user_id_key_unique')) {
                throw $e;
            }

            $record = InventoryIdempotencyKey::query()
                ->where('user_id', $userId)->where('key', $idempotencyKey)->first();

            if ($record === null || ! hash_equals($record->request_hash, $requestHash)) {
                throw new BusinessException('مفتاح عدم التكرار هذا مستخدم مسبقًا مع بيانات مختلفة.', [], 409);
            }

            $model = $modelClass::query()->with($relations)->findOrFail((int) ($record->response[0] ?? 0));
            // Validate access against any branch associated with the created entity (e.g. origin or destination).
            $branches = [];
            foreach (['branch_id', 'from_branch_id', 'to_branch_id'] as $attribute) {
                $value = $model->getAttribute($attribute);
                if ($value !== null) {
                    $branches[] = (int) $value;
                }
            }
            app(BranchAccess::class)->assertBranch($userId, ...$branches);

            return $model;
        }
    }
}
