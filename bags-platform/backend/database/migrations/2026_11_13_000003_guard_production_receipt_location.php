<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        ConcurrentIndex::ensure('production_receipts_location_id_index', 'ON production_receipts (location_id)');
        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");
            DB::unprepared(<<<'SQL'
                DO $$ BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM pg_constraint
                        WHERE conrelid = 'production_receipts'::regclass AND conname = 'production_receipts_location_branch_fk'
                    ) THEN
                        ALTER TABLE production_receipts ADD CONSTRAINT production_receipts_location_branch_fk
                            FOREIGN KEY (location_id, branch_id) REFERENCES locations (id, branch_id)
                            ON UPDATE RESTRICT ON DELETE RESTRICT NOT VALID;
                    END IF;
                END $$;
                SQL);
        });
        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");
            DB::statement('ALTER TABLE production_receipts VALIDATE CONSTRAINT production_receipts_location_branch_fk');
        });
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE production_receipts DROP CONSTRAINT IF EXISTS production_receipts_location_branch_fk');
        ConcurrentIndex::drop('production_receipts_location_id_index');
    }
};
