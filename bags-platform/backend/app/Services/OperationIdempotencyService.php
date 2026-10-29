<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Scoped operation idempotency coordinator.
 *
 * Guarantees exactly-once execution for state-changing financial and inventory intake operations
 * by tracking idempotency keys scoped to the authenticated actor and verifying request payload hashes.
 */
class OperationIdempotencyService
{
    /**
     * Execute an operation idempotently or return the previously recorded result.
     *
     * @param  string  $operation  Unique operation identifier (e.g. purchase_receipt, supplier_payment)
     * @param  Model  $actor  Authenticated user or customer instance
     * @param  string|null  $key  Idempotency key supplied by the client
     * @param  array<string, mixed>  $payload  Request payload for canonical hash comparison
     * @param  callable(): Model  $execute  Operation execution callback
     * @return Model
     *
     * @throws BusinessException
     */
    public function run(string $operation, Model $actor, ?string $key, array $payload, callable $execute): Model
    {
        if ($key === null || ! preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $key)) {
            throw new BusinessException('ترويسة Idempotency-Key مطلوبة (حتى 128 حرفًا إنجليزيًا أو رقمًا).');
        }
        $scope = ['operation' => $operation, 'actor_type' => $actor->getMorphClass(), 'actor_id' => $actor->getKey(), 'key' => $key];
        $hash = IdempotencyFingerprint::hash($payload);

        return DB::transaction(function () use ($scope, $hash, $execute): Model {
            $inserted = DB::table('operation_idempotency_keys')->insertOrIgnore([
                ...$scope, 'request_hash' => $hash, 'created_at' => now(),
            ]);
            $record = DB::table('operation_idempotency_keys')->where($scope)->lockForUpdate()->first();
            if (! $inserted) {
                if (! hash_equals($record->request_hash, $hash)) {
                    throw new BusinessException('مفتاح عدم التكرار مستخدم مع بيانات مختلفة.', [], 409);
                }
                $result = $record->result_type::query()->find($record->result_id);
                if ($result === null) {
                    throw new BusinessException('العملية سبق تنفيذها ثم أزيل مستندها؛ استخدم عملية جديدة.', [], 409);
                }

                return $result;
            }
            $result = $execute();
            DB::table('operation_idempotency_keys')->where($scope)->update([
                'result_type' => $result::class, 'result_id' => $result->getKey(),
            ]);

            return $result;
        }, 3);
    }
}
