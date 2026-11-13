<?php

declare(strict_types=1);

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;

/** Explicit statement budgets that cannot leak across PgBouncer transactions. */
final class StatementBudget
{
    public static function run(int $milliseconds, callable $callback, bool $readOnly = true): mixed
    {
        if (DB::getDriverName() !== 'pgsql') {
            return $callback();
        }

        return DB::transaction(function () use ($milliseconds, $callback, $readOnly): mixed {
            // Reports read several aggregates; a stable snapshot prevents a sale from
            // landing between two statements and producing inconsistent totals.
            // Nested calls (tests, command wrappers) cannot change isolation after
            // their parent already executed statements.
            // Callers performing only reads should pass $readOnly = true; writers keep
            // REPEATABLE READ alone so snapshot stability stays intact.
            $nested = DB::transactionLevel() > 1;
            if (! $nested) {
                DB::statement($readOnly
                    ? 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY'
                    : 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }

            $previous = $nested ? DB::scalar("SELECT current_setting('statement_timeout')") : null;
            DB::select("SELECT set_config('statement_timeout', ?, true)", [max(1, $milliseconds).'ms']);

            // A failed callback rolls back the savepoint and its settings. On
            // success releasing a savepoint keeps settings in the outer transaction.
            $result = $callback();
            if ($previous !== null) {
                DB::select("SELECT set_config('statement_timeout', ?, true)", [$previous]);
            }

            return $result;
        });
    }
}
