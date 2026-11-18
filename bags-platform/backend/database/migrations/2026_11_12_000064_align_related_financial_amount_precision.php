<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const AMOUNTS = [
        'coupon_usages' => ['discount_amount'],
        'orders' => ['cash_amount', 'card_amount'],
        'return_requests' => ['total_refund_amount'],
        'supplier_payments' => ['amount'],
        'wallet_adjustments' => ['amount'],
        'wholesale_payment_allocations' => ['amount'],
        'wholesale_payment_notices' => ['amount'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->setPrecision(14);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::AMOUNTS as $table => $columns) {
            foreach ($columns as $column) {
                if (DB::table($table)->whereRaw("abs({$column}) >= 10000000000 AND {$column} <> 'NaN'::numeric")->exists()) {
                    throw new RuntimeException("Cannot narrow {$table}.{$column}: a stored amount exceeds numeric(12,2).");
                }
            }
        }

        $this->setPrecision(12);
    }

    private function setPrecision(int $precision): void
    {
        foreach (self::AMOUNTS as $table => $columns) {
            DB::transaction(function () use ($table, $columns, $precision): void {
                // Keep each table's DDL lock in its own transaction.
                DB::statement("SET LOCAL lock_timeout = '3s'");
                DB::statement("LOCK TABLE {$table} IN ACCESS EXCLUSIVE MODE");

                // PostgreSQL rejects type changes when an UPDATE OF trigger
                // references the column. Recreate those guards atomically.
                $triggers = DB::select(<<<'SQL'
                    SELECT tgname AS name, tgenabled AS enabled, pg_get_triggerdef(t.oid) AS definition
                    FROM pg_trigger t
                    WHERE t.tgrelid = to_regclass(?) AND NOT t.tgisinternal
                      AND EXISTS (
                          SELECT 1 FROM pg_attribute a
                          WHERE a.attrelid = t.tgrelid AND a.attnum = ANY(t.tgattr)
                            AND a.attname = ANY(string_to_array(?, ','))
                      )
                    ORDER BY tgname
                    SQL, [$table, implode(',', $columns)], false);
                $grammar = DB::connection()->getQueryGrammar();
                foreach ($triggers as $trigger) {
                    DB::statement('DROP TRIGGER '.$grammar->wrap($trigger->name)." ON {$table}");
                }

                $changes = array_map(
                    fn (string $column): string => "ALTER COLUMN {$column} TYPE numeric({$precision}, 2)",
                    $columns,
                );
                DB::statement("ALTER TABLE {$table} ".implode(', ', $changes));

                foreach ($triggers as $trigger) {
                    DB::unprepared($trigger->definition);
                    $mode = match ($trigger->enabled) {
                        'D' => 'DISABLE',
                        'R' => 'ENABLE REPLICA',
                        'A' => 'ENABLE ALWAYS',
                        default => null,
                    };
                    if ($mode !== null) {
                        DB::statement("ALTER TABLE {$table} {$mode} TRIGGER ".$grammar->wrap($trigger->name));
                    }
                }
            });
        }
    }
};
