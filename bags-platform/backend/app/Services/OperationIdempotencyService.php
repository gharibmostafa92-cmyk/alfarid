<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class OperationIdempotencyService
{
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
