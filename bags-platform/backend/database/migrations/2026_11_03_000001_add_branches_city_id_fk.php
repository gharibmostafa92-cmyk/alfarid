<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Branches city link: pin branches.city_id to cities.id with
 * ON DELETE SET NULL so deleting a city never orphans a branch,
 * and the city text column (synced from the City record on write)
 * cannot drift from the reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DO $$ BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = \'branches_city_id_fk\') THEN
                ALTER TABLE branches ADD CONSTRAINT branches_city_id_fk
                    FOREIGN KEY (city_id) REFERENCES cities (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL NOT VALID;
            END IF;
        END $$;');
        DB::statement('ALTER TABLE branches VALIDATE CONSTRAINT branches_city_id_fk');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE branches DROP CONSTRAINT IF EXISTS branches_city_id_fk');
    }
};
