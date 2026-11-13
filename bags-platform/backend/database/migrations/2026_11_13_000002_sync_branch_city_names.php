<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '3s'");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sync_branch_city_name() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    UPDATE branches SET city = NULL, city_id = NULL WHERE city_id = OLD.id;
                    RETURN OLD;
                END IF;
                UPDATE branches
                SET city = COALESCE(NULLIF(NEW.name_ar, ''), NEW.name_en)
                WHERE city_id = NEW.id;
                RETURN NEW;
            END;
            $$;
            DROP TRIGGER IF EXISTS branches_sync_city_rename ON cities;
            DROP TRIGGER IF EXISTS branches_sync_city_delete ON cities;
            CREATE TRIGGER branches_sync_city_rename AFTER UPDATE OF name_ar, name_en ON cities
                FOR EACH ROW WHEN (OLD.name_ar IS DISTINCT FROM NEW.name_ar OR OLD.name_en IS DISTINCT FROM NEW.name_en)
                EXECUTE FUNCTION sync_branch_city_name();
            CREATE TRIGGER branches_sync_city_delete BEFORE DELETE ON cities
                FOR EACH ROW EXECUTE FUNCTION sync_branch_city_name();
            UPDATE branches AS branch
            SET city = COALESCE(NULLIF(city.name_ar, ''), city.name_en)
            FROM cities AS city
            WHERE branch.city_id = city.id
                AND branch.city IS DISTINCT FROM COALESCE(NULLIF(city.name_ar, ''), city.name_en);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS branches_sync_city_rename ON cities;
            DROP TRIGGER IF EXISTS branches_sync_city_delete ON cities;
            DROP FUNCTION IF EXISTS sync_branch_city_name();
            SQL);
    }
};
