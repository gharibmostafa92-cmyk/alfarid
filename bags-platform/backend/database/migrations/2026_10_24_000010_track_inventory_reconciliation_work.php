<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE inventory_reconcile_work (
    key text PRIMARY KEY,
    branch_id bigint NOT NULL,
    location_id bigint NULL,
    product_variant_id bigint NOT NULL,
    revision bigint NOT NULL DEFAULT 1
);
CREATE INDEX inventory_reconcile_work_branch_index ON inventory_reconcile_work (branch_id, key);
CREATE FUNCTION mark_inventory_reconciliation_work() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE row_data record;
BEGIN
    IF TG_OP IN ('UPDATE', 'DELETE') THEN
        row_data := OLD;
        INSERT INTO inventory_reconcile_work AS w (key, branch_id, location_id, product_variant_id)
        VALUES (row_data.branch_id || ':' || COALESCE(row_data.location_id, 0) || ':' || row_data.product_variant_id,
            row_data.branch_id, row_data.location_id, row_data.product_variant_id)
        ON CONFLICT (key) DO UPDATE SET revision = w.revision + 1;
    END IF;
    IF TG_OP IN ('INSERT', 'UPDATE') THEN
        row_data := NEW;
        INSERT INTO inventory_reconcile_work AS w (key, branch_id, location_id, product_variant_id)
        VALUES (row_data.branch_id || ':' || COALESCE(row_data.location_id, 0) || ':' || row_data.product_variant_id,
            row_data.branch_id, row_data.location_id, row_data.product_variant_id)
        ON CONFLICT (key) DO UPDATE SET revision = w.revision + 1;
    END IF;
    RETURN NULL;
END; $$;
CREATE TRIGGER stock_levels_reconciliation_work AFTER INSERT OR UPDATE OR DELETE ON stock_levels
FOR EACH ROW EXECUTE FUNCTION mark_inventory_reconciliation_work();
CREATE TRIGGER stock_movements_reconciliation_work AFTER INSERT ON stock_movements
FOR EACH ROW EXECUTE FUNCTION mark_inventory_reconciliation_work();
INSERT INTO inventory_reconcile_work (key, branch_id, location_id, product_variant_id)
SELECT branch_id || ':' || COALESCE(location_id, 0) || ':' || product_variant_id, branch_id, location_id, product_variant_id
FROM (SELECT branch_id, location_id, product_variant_id FROM stock_levels
      UNION SELECT branch_id, location_id, product_variant_id FROM stock_movements) k;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER stock_movements_reconciliation_work ON stock_movements;
DROP TRIGGER stock_levels_reconciliation_work ON stock_levels;
DROP FUNCTION mark_inventory_reconciliation_work();
DROP TABLE inventory_reconcile_work;
SQL);
    }
};
