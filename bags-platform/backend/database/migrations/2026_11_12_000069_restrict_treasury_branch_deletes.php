<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F14: deleting a branch must never turn its treasuries into central (branch_id = null)
 * accounts, which would let any branch post expenses against them.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->replaceForeignKey('RESTRICT', 'r');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->replaceForeignKey('SET NULL', 'n');
    }

    private function replaceForeignKey(string $action, string $code): void
    {
        $constraint = null;

        DB::transaction(function () use ($action, $code, &$constraint): void {
            DB::statement("SET LOCAL lock_timeout = '2s'");

            // Discover the real FK by column instead of guessing its constraint
            // name, so renamed/regenerated constraints deploy safely.
            $current = DB::selectOne(<<<'SQL'
                SELECT c.conname, c.confdeltype
                FROM pg_constraint c
                JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
                WHERE c.conrelid = 'treasuries'::regclass
                  AND c.contype = 'f'
                  AND a.attname = 'branch_id'
                ORDER BY c.conname
                LIMIT 1
                SQL);

            if ($current === null) {
                $constraint = 'treasuries_branch_id_foreign';
                DB::statement("ALTER TABLE treasuries ADD CONSTRAINT {$constraint} FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE {$action} NOT VALID");

                return;
            }

            $constraint = $current->conname;
            if ($current->confdeltype === $code) {
                return;
            }

            $replacement = substr($constraint.'_new', 0, 63);
            DB::statement("ALTER TABLE treasuries ADD CONSTRAINT {$replacement} FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE {$action} NOT VALID");
            DB::statement("ALTER TABLE treasuries DROP CONSTRAINT {$constraint}");
            DB::statement("ALTER TABLE treasuries RENAME CONSTRAINT {$replacement} TO {$constraint}");
        });

        DB::transaction(function () use ($constraint): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");
            DB::statement("ALTER TABLE treasuries VALIDATE CONSTRAINT {$constraint}");
        });
    }
};
