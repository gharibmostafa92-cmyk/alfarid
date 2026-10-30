<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\CustomerWalletTransaction;
use App\Models\ProductionReceipt;
use App\Models\SupplierDelivery;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * Scoped operation idempotency coordinator.
 *
 * Guarantees exactly-once execution for state-changing financial and inventory intake operations
 * by tracking idempotency keys scoped to the authenticated actor and verifying request payload hashes.
 */
class OperationIdempotencyService
{
    // Result-only aliases avoid changing existing polymorphic relation queries.
    private const RESULT_TYPES = [
        'supplier_payment' => SupplierPayment::class,
        'supplier_delivery' => SupplierDelivery::class,
        'supplier_invoice' => SupplierInvoice::class,
        'production_receipt' => ProductionReceipt::class,
        'customer_wallet_transaction' => CustomerWalletTransaction::class,
    ];

    /**
     * Execute an operation idempotently or return the previously recorded result.
     *
     * @param  string  $operation  Unique operation identifier (e.g. purchase_receipt, supplier_payment)
     * @param  Model  $actor  Authenticated user or customer instance
     * @param  string|null  $key  Idempotency key supplied by the client
     * @param  array<string, mixed>  $payload  Request payload for canonical hash comparison
     * @param  callable(): Model  $execute  Operation execution callback
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
            $previousTimeout = DB::selectOne("SELECT current_setting('lock_timeout') AS value")->value;
            DB::statement("SET LOCAL lock_timeout = '3s'");
            $inserted = DB::table('operation_idempotency_keys')->insertOrIgnore([
                ...$scope, 'request_hash' => $hash, 'created_at' => now(),
            ]);
            $record = DB::table('operation_idempotency_keys')->where($scope)->lockForUpdate()->first();
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
                // M1: store the morph alias, not the FQCN — a class rename
                // must not turn every replay into a fatal error.
                'result_type' => array_search($result::class, self::RESULT_TYPES, true) ?: $result->getMorphClass(), 'result_id' => $result->getKey(),
            ]);

            return $result;
            // Callbacks may upload files or invoke external services. Let the
            // client retry a failed transaction instead of repeating those effects.
        }, 1);
    }

    /**
     * Resolve a stored idempotency result via its morph alias.
     *
     * Legacy rows stored the FQCN; resolve those directly for BC. New rows
     * store getMorphClass() and resolve through the morph map.
     */
    private static function findResult(mixed $type, mixed $id): ?Model
    {
        if (! is_string($type) || $type === '' || $id === null) {
            return null;
        }

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
