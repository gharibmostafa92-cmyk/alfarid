<?php

declare(strict_types=1);

namespace App\Services;

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
            $previous = DB::selectOne("SELECT current_setting('statement_timeout') AS budget")->budget;
            DB::select("SELECT set_config('statement_timeout', ?, true)", [max(1, $milliseconds).'ms']);
            $result = $callback();
            DB::select("SELECT set_config('statement_timeout', ?, true)", [$previous]);

            return $result;
        });
    }
}
