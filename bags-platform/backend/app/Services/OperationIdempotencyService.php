<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Exceptions\RetryableConflict;
use App\Models\CustomerWalletTransaction;
use App\Models\ProductionReceipt;
use App\Models\SupplierDelivery;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Coordinates scoped idempotent execution for state-changing operations.
 *
 * Guarantees exactly-once execution across concurrent requests by scoping idempotency
 * keys to the authenticated actor and comparing canonical payload hashes.
 */
class OperationIdempotencyService
{
    // Maps internal result types to concrete models without altering polymorphic relation queries.
    private const RESULT_TYPES = [
        'supplier_payment' => SupplierPayment::class,
        'supplier_delivery' => SupplierDelivery::class,
        'supplier_invoice' => SupplierInvoice::class,
        'production_receipt' => ProductionReceipt::class,
        'customer_wallet_transaction' => CustomerWalletTransaction::class,
    ];

    /**
     * Execute an operation idempotently or return the cached result of a previous run.
     *
     * @param  string  $operation  Unique operation identifier (e.g., purchase_receipt, supplier_payment).
     * @param  Model  $actor  Authenticated entity executing the operation.
     * @param  string|null  $key  Client-supplied idempotency key.
     * @param  array<string, mixed>  $payload  Request payload used to detect parameter tampering or key reuse.
     * @param  callable(): Model  $execute  Operation execution callback invoked on the first attempt.
     * @return Model Stored or newly created model instance.
     *
     * @throws BusinessException If the key is missing/invalid, payload diverges, or the record was deleted.
     * @throws RetryableConflict If a concurrent request is currently holding the key lock.
     */
    public function run(string $operation, Model $actor, ?string $key, array $payload, callable $execute): Model
    {
        if ($key === null || ! preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $key)) {
            throw new BusinessException('ترويسة Idempotency-Key مطلوبة (حتى 128 حرفًا إنجليزيًا أو رقمًا).');
        }
        $scope = ['operation' => $operation, 'actor_type' => $actor->getMorphClass(), 'actor_id' => $actor->getKey(), 'key' => $key];
        $hash = IdempotencyFingerprint::hash($payload);

        return DB::transaction(function () use ($scope, $hash, $execute): Model {
            $previousTimeout = DB::selectOne("SELECT current_setting('lock_timeout') AS value")->value;
            // Bound lock wait time to quickly fail concurrent duplicates instead of exhausting connection pool.
            DB::statement("SET LOCAL lock_timeout = '3s'");
            try {
                $inserted = DB::table('operation_idempotency_keys')->insertOrIgnore([
                    ...$scope, 'request_hash' => $hash, 'created_at' => now(),
                ]);
                $record = DB::table('operation_idempotency_keys')->where($scope)->lockForUpdate()->first();
            } catch (QueryException $e) {
                // Postgres 55P03 triggers when insertOrIgnore waits on an uncommitted concurrent transaction.
                if (($e->errorInfo[0] ?? null) === '55P03') {
                    throw new RetryableConflict('العملية مشغولة؛ أعد المحاولة بنفس المفتاح.', $e);
                }
                throw $e;
            }
            // Restore connection-level lock timeout to avoid leaking the 3-second limit to subsequent queries.
            DB::selectOne("SELECT set_config('lock_timeout', ?, true)", [$previousTimeout]);
            if ($record === null) {
                throw new BusinessException('العملية مشغولة؛ أعد المحاولة بنفس المفتاح.', [], 409);
            }
            if (! $inserted) {
                if (! hash_equals($record->request_hash, $hash)) {
                    throw new BusinessException('مفتاح عدم التكرار مستخدم مع بيانات مختلفة.', [], 409);
                }
                $result = self::findResult($record->result_type ?? null, $record->result_id ?? null);
                if ($result === null) {
                    throw new BusinessException('العملية سبق تنفيذها ثم أزيل مستندها؛ استخدم عملية جديدة.', [], 409);
                }

                return $result;
            }
            $result = $execute();
            DB::table('operation_idempotency_keys')->where($scope)->update([
                // Store morph aliases instead of FQCNs to prevent model namespace refactoring from breaking historical replays.
                'result_type' => array_search($result::class, self::RESULT_TYPES, true) ?: $result->getMorphClass(), 'result_id' => $result->getKey(),
            ]);

            return $result;
        // Limit to 1 attempt to avoid repeating non-transactional side effects (e.g., file storage, notifications).
        }, 1);
    }

    /**
     * Resolves a stored model instance by morph alias or legacy FQCN.
     *
     * @param  mixed  $type  Morph alias or legacy fully-qualified class name.
     * @param  mixed  $id  Model primary key.
     * @return Model|null
     */
    private static function findResult(mixed $type, mixed $id): ?Model
    {
        if (! is_string($type) || $type === '' || $id === null) {
            return null;
        }

        // Resolves custom map first, then Laravel morph map, and falls back to FQCN for backward compatibility.
        $class = self::RESULT_TYPES[$type] ?? Relation::getMorphedModel($type) ?? $type;

        if (! is_string($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        /** @var Model $instance */
        $instance = new $class;

        if (! $instance instanceof Model) {
            return null;
        }

        return $instance->newQuery()->find($id);
    }
}
