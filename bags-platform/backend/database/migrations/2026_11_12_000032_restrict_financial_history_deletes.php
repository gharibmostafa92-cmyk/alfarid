<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const REFERENCES = [
        ['wholesale_payment_notices', 'customer_id', 'customers', 'CASCADE', 'c'],
        ['treasury_transactions', 'shift_id', 'pos_shifts', 'SET NULL', 'n'],
        ['expenses', 'shift_id', 'pos_shifts', 'SET NULL', 'n'],
    ];

    public function up(): void
    {
        foreach (self::REFERENCES as [$table, $column, $parent]) {
            $this->replaceForeignKey($table, $column, $parent, 'RESTRICT', 'r');
        }
    }

    public function down(): void
    {
        foreach (self::REFERENCES as [$table, $column, $parent, $action, $code]) {
            $this->replaceForeignKey($table, $column, $parent, $action, $code);
        }
    }

    private function replaceForeignKey(string $table, string $column, string $parent, string $action, string $code): void
    {
        $constraint = "{$table}_{$column}_foreign";
        $replacement = $constraint.'_new';

        DB::transaction(function () use ($table, $column, $parent, $action, $code, $constraint, $replacement): void {
            DB::statement("SET LOCAL lock_timeout = '2s'");
            $current = DB::selectOne('SELECT confdeltype FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass', [$constraint, $table]);
            if ($current?->confdeltype === $code) {
                return;
            }

            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$replacement} FOREIGN KEY ({$column}) REFERENCES {$parent} (id) ON DELETE {$action} NOT VALID");
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
            DB::statement("ALTER TABLE {$table} RENAME CONSTRAINT {$replacement} TO {$constraint}");
        });

        DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$constraint}");
    }
};
