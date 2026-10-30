<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * M2: a stock row must never pair branch A with a location owned by
     * branch B. The application check lives in InventoryService::lockLevels;
     * this migration pins the same invariant in the database with composite
     * foreign keys (added NOT VALID, then validated to avoid long locks).
     */
    public function up(): void
    {
        // L2: idempotent — الـ migration شغال برا transaction، وإعادة
        // تشغيله بعد فشل في النص لازم تكمل مش تفشل "already exists".
        DB::statement('DO $$ BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = \'locations_id_branch_id_unique\') THEN
                ALTER TABLE locations ADD CONSTRAINT locations_id_branch_id_unique UNIQUE (id, branch_id);
            END IF;
        END $$;');

        // L2: ON UPDATE RESTRICT مش CASCADE — الدفتر التاريخي ما يتكتبش
        // تاني لو locations.branch_id اتغير يومًا ما.
        DB::statement('DO $$ BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = \'stock_levels_location_branch_fk\') THEN
                ALTER TABLE stock_levels ADD CONSTRAINT stock_levels_location_branch_fk
                    FOREIGN KEY (location_id, branch_id) REFERENCES locations (id, branch_id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT NOT VALID;
            END IF;
        END $$;');
        DB::statement('DO $$ BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = \'stock_movements_location_branch_fk\') THEN
                ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_location_branch_fk
                    FOREIGN KEY (location_id, branch_id) REFERENCES locations (id, branch_id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT NOT VALID;
            END IF;
        END $$;');

        DB::statement('ALTER TABLE stock_levels VALIDATE CONSTRAINT stock_levels_location_branch_fk');
        DB::statement('ALTER TABLE stock_movements VALIDATE CONSTRAINT stock_movements_location_branch_fk');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT IF EXISTS stock_movements_location_branch_fk');
        DB::statement('ALTER TABLE stock_levels DROP CONSTRAINT IF EXISTS stock_levels_location_branch_fk');
        DB::statement('ALTER TABLE locations DROP CONSTRAINT IF EXISTS locations_id_branch_id_unique');
    }
};
