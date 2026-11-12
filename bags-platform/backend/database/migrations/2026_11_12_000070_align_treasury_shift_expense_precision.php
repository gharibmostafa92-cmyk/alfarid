<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F17: unify monetary precision on the cash path with the rest of the finance
 * schema (numeric(14,2)). Treasuries were already 14,2; POS shift columns and
 * expense amounts were 12,2 and could overflow at different thresholds.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const COLUMNS = [
        'pos_shifts' => [
            'opening_cash',
            'cash_sales_total',
            'card_sales_total',
            'cash_returns_total',
            'cash_expenses_total',
            'expected_closing_cash',
            'actual_closing_cash',
            'cash_difference',
        ],
        'expenses' => ['amount'],
    ];

    public function up(): void
    {
        $pending = $this->columnsBelowPrecision(14);
        if ($pending === []) {
            return;
        }

        $this->setPrecision(14, $pending);
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $overflow = DB::table($table)
                    ->whereRaw("ABS({$column}) >= 10000000000")
                    ->exists();
                if ($overflow) {
                    throw new RuntimeException("Cannot shrink {$table}.{$column} to numeric(12, 2): values >= 10^10 exist.");
                }
            }
        }

        $pending = $this->columnsAbovePrecision(12);
        if ($pending === []) {
            return;
        }

        $this->setPrecision(12, $pending);
    }

    /**
     * Columns still narrower than the target precision, read from the
     * information_schema so re-running the migration after a partial retry
     * (or a lock-timeout failure) is a cheap no-op instead of re-locking
     * tables that are already at the target width.
     *
     * @return array<string, list<string>>
     */
    private function columnsBelowPrecision(int $precision): array
    {
        return $this->columnsMismatchingPrecision(fn (?int $p): bool => $p === null || $p < $precision);
    }

    /**
     * @return array<string, list<string>>
     */
    private function columnsAbovePrecision(int $precision): array
    {
        return $this->columnsMismatchingPrecision(fn (?int $p): bool => $p !== null && $p > $precision);
    }

    /**
     * @param  callable(?int):bool  $needsChange
     * @return array<string, list<string>>
     */
    private function columnsMismatchingPrecision(callable $needsChange): array
    {
        $pending = [];
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $current = DB::table('information_schema.columns')
                    ->where('table_schema', DB::getSchemaBuilder()->getCurrentSchemaName() ?? 'public')
                    ->where('table_name', $table)
                    ->where('column_name', $column)
                    ->value('numeric_precision');
                if ($needsChange($current === null ? null : (int) $current)) {
                    $pending[$table][] = $column;
                }
            }
        }

        return $pending;
    }

    /**
     * @param  array<string, list<string>>  $targets
     */
    private function setPrecision(int $precision, array $targets): void
    {
        foreach ($targets as $table => $columns) {
            DB::transaction(function () use ($table, $columns, $precision): void {
                DB::statement("SET LOCAL lock_timeout = '3s'");
                $alterations = array_map(
                    fn (string $column): string => "ALTER COLUMN {$column} TYPE numeric({$precision}, 2)",
                    $columns,
                );
                DB::statement("ALTER TABLE {$table} ".implode(', ', $alterations));
            });
        }
    }
};
