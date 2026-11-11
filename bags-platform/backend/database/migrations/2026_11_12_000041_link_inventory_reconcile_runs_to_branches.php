<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");
            if (! DB::scalar("SELECT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'inventory_reconcile_runs'::regclass AND conname = 'inventory_reconcile_runs_branch_id_foreign')")) {
                DB::statement('ALTER TABLE inventory_reconcile_runs ADD CONSTRAINT inventory_reconcile_runs_branch_id_foreign
                    FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE RESTRICT NOT VALID');
            }
        });

        DB::statement('ALTER TABLE inventory_reconcile_runs VALIDATE CONSTRAINT inventory_reconcile_runs_branch_id_foreign');
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");
            DB::statement('ALTER TABLE inventory_reconcile_runs DROP CONSTRAINT IF EXISTS inventory_reconcile_runs_branch_id_foreign');
        });
    }
};
