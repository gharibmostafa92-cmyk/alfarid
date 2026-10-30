<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\OperationIdempotencyService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class OperationIdempotencyRegressionTest extends TestCase
{
    public function test_a_concurrency_failure_does_not_repeat_external_side_effects(): void
    {
        // A connection-local table allows a real root transaction and retry,
        // without a test wrapper transaction or changes to persisted data.
        DB::statement(<<<'SQL'
            CREATE TEMPORARY TABLE operation_idempotency_keys (
                operation varchar(255), actor_type varchar(255), actor_id bigint,
                key varchar(128), request_hash varchar(64), created_at timestamp,
                result_type varchar(255), result_id bigint,
                UNIQUE (operation, actor_type, actor_id, key)
            )
            SQL);

        $actor = new User;
        $actor->id = 1;
        $calls = 0;
        $original = new RuntimeException('deadlock detected');

        try {
            try {
                app(OperationIdempotencyService::class)->run('regression', $actor, 'external-effect', [], function () use (&$calls, $original): never {
                    $calls++;

                    throw $original;
                });
                $this->fail('The concurrency exception should reach the caller.');
            } catch (RuntimeException $exception) {
                $this->assertSame($original, $exception);
            }

            $this->assertSame(1, $calls, 'An operation callback may send messages or perform uploads; it must not be retried implicitly.');
        } finally {
            DB::statement('DROP TABLE pg_temp.operation_idempotency_keys');
        }
    }
}
