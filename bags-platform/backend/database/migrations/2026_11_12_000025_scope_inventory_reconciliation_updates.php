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
            CREATE OR REPLACE FUNCTION mark_changed_stock_level_for_reconciliation() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                INSERT INTO inventory_reconcile_work AS work (key, branch_id, location_id, product_variant_id)
                SELECT DISTINCT branch_id || ':' || COALESCE(location_id, 0) || ':' || product_variant_id AS key,
                    branch_id, location_id, product_variant_id
                FROM (VALUES
                    (OLD.branch_id, OLD.location_id, OLD.product_variant_id),
                    (NEW.branch_id, NEW.location_id, NEW.product_variant_id)
                ) AS changed(branch_id, location_id, product_variant_id)
                ORDER BY key
                ON CONFLICT (key) DO UPDATE SET revision = work.revision + 1, checked_at = NULL;

                RETURN NULL;
            END;
            $$;

            CREATE OR REPLACE TRIGGER stock_levels_reconciliation_work
                AFTER INSERT OR DELETE ON stock_levels
                FOR EACH ROW EXECUTE FUNCTION mark_inventory_reconciliation_work();

            CREATE TRIGGER stock_levels_reconciliation_update
                AFTER UPDATE ON stock_levels
                FOR EACH ROW
                WHEN (
                    (OLD.branch_id, OLD.location_id, OLD.product_variant_id, OLD.on_hand, OLD.reserved, OLD.damaged, OLD.in_transit)
                    IS DISTINCT FROM
                    (NEW.branch_id, NEW.location_id, NEW.product_variant_id, NEW.on_hand, NEW.reserved, NEW.damaged, NEW.in_transit)
                )
                EXECUTE FUNCTION mark_changed_stock_level_for_reconciliation();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER stock_levels_reconciliation_update ON stock_levels;
            CREATE OR REPLACE TRIGGER stock_levels_reconciliation_work
                AFTER INSERT OR UPDATE OR DELETE ON stock_levels
                FOR EACH ROW EXECUTE FUNCTION mark_inventory_reconciliation_work();
            DROP FUNCTION mark_changed_stock_level_for_reconciliation();
            SQL);
    }
};
