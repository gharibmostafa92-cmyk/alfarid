<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Exceptions\RetryableConflict;
use App\Models\User;
use App\Services\Shared\OperationIdempotencyService;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

class OperationIdempotencyDeadlockTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function transientStates(): array
    {
        return [
            'deadlock' => ['40P01'],
            'serialization failure' => ['40001'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('transientStates')]
    public function test_transient_concurrency_errors_map_to_retryable_conflict(string $state): void
    {
        // A connection-local table allows a real root transaction and rollback,
        // without a test wrapper transaction or changes to persisted data.
        DB::statement(<<<'SQL'
            CREATE TEMPORARY TABLE operation_idempotency_keys (
                operation varchar(255), actor_type varchar(255), actor_id bigint,
                key varchar(128), request_hash varchar(64), created_at timestamp,
                result_type varchar(255), result_id bigint,
                UNIQUE (operation, actor_type, actor_id, key)
            )
            SQL);

        try {
            $actor = new User;
            $actor->id = 1;
            $calls = 0;
            $previous = new PDOException('concurrency failure');
            $previous->errorInfo = [$state, 7, 'concurrency failure'];
            $failure = new QueryException('pgsql', 'select 1', [], $previous);

            try {
                app(OperationIdempotencyService::class)->run('deadlock-op', $actor, 'op-key', ['a' => 1], function () use (&$calls, $failure): never {
                    $calls++;

                    throw $failure;
                });
                $this->fail('A transient concurrency error must surface as RetryableConflict.');
            } catch (RetryableConflict $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }

            $this->assertSame(1, $calls, 'The rolled-back callback must not be retried implicitly.');
            $this->assertDatabaseMissing('operation_idempotency_keys', ['key' => 'op-key']);
        } finally {
            DB::statement('DROP TABLE pg_temp.operation_idempotency_keys');
        }
    }

    public function test_nested_transaction_deadlock_exception_maps_to_retryable_conflict(): void
    {
        // H2: a deadlock inside a nested DB::transaction surfaces as
        // DeadlockException (a PDOException, not a QueryException) and must
        // still surface as RetryableConflict so the client retries safely.
        DB::statement(<<<'SQL'
            CREATE TEMPORARY TABLE operation_idempotency_keys (
                operation varchar(255), actor_type varchar(255), actor_id bigint,
                key varchar(128), request_hash varchar(64), created_at timestamp,
                result_type varchar(255), result_id bigint,
                UNIQUE (operation, actor_type, actor_id, key)
            )
            SQL);

        try {
            $actor = new User;
            $actor->id = 1;
            $calls = 0;

            try {
                app(OperationIdempotencyService::class)->run('nested-deadlock-op', $actor, 'op-key', ['a' => 1], function () use (&$calls): never {
                    $calls++;

                    throw new DeadlockException('deadlock detected');
                });
                $this->fail('A nested-transaction deadlock must surface as RetryableConflict.');
            } catch (RetryableConflict $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }

            $this->assertSame(1, $calls, 'The rolled-back callback must not be retried implicitly.');
            $this->assertDatabaseMissing('operation_idempotency_keys', ['key' => 'op-key']);
        } finally {
            DB::statement('DROP TABLE pg_temp.operation_idempotency_keys');
        }
    }
}
