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
        $constraint = 'treasuries_branch_id_foreign';
        $replacement = $constraint.'_new';

        DB::transaction(function () use ($action, $code, $constraint, $replacement): void {
            DB::statement("SET LOCAL lock_timeout = '2s'");
            $current = DB::selectOne(
                'SELECT confdeltype FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass',
                [$constraint, 'treasuries'],
            );
            if ($current === null) {
                throw new RuntimeException('Missing treasuries.branch_id foreign key; refusing to deploy.');
            }
            if ($current->confdeltype === $code) {
                return;
            }

            DB::statement("ALTER TABLE treasuries ADD CONSTRAINT {$replacement} FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE {$action} NOT VALID");
            DB::statement("ALTER TABLE treasuries DROP CONSTRAINT {$constraint}");
            DB::statement("ALTER TABLE treasuries RENAME CONSTRAINT {$replacement} TO {$constraint}");
        });

        DB::statement("ALTER TABLE treasuries VALIDATE CONSTRAINT {$constraint}");
    }
};
