<?php

declare(strict_types=1);

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;

/** Explicit statement budgets that cannot leak across PgBouncer transactions. */
final class StatementBudget
{
    public static function run(int $milliseconds, callable $callback): mixed
    {
        if (DB::getDriverName() !== 'pgsql') {
            return $callback();
        }

        return DB::transaction(function () use ($milliseconds, $callback): mixed {
            // Reports read several aggregates; a stable snapshot prevents a sale from
            // landing between two statements and producing inconsistent totals.
            // Nested calls (tests, command wrappers) cannot change isolation after
            // their parent already executed statements.
            // NOTE: no READ ONLY — this helper also wraps writers (e.g. inventory:reconcile
            // records its run row); REPEATABLE READ alone gives the stable snapshot.
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            $previous = DB::selectOne("SELECT current_setting('statement_timeout') AS budget")->budget;
            DB::select("SELECT set_config('statement_timeout', ?, true)", [max(1, $milliseconds).'ms']);

            try {
                return $callback();
            } finally {
                try {
                    DB::select("SELECT set_config('statement_timeout', ?, true)", [$previous]);
                } catch (\Throwable) {
                    // PostgreSQL aborts the active transaction on SQL error (25P02);
                    // avoid masking the original exception, as ROLLBACK reverts local set_config.
                }
            }
        });
    }
}
