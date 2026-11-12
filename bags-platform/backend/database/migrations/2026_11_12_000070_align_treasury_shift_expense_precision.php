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
        $this->setPrecision(14);
    }

    public function down(): void
    {
        $this->setPrecision(12);
    }

    private function setPrecision(int $precision): void
    {
        foreach (self::COLUMNS as $table => $columns) {
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
