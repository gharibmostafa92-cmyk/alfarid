<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F-11: mark_inventory_reconciliation_work() bumps revision without clearing
 * checked_at, so a key that changed right now keeps a fresh timestamp and
 * sinks to the back of the (branch_id, checked_at NULLS FIRST, key) queue —
 * the opposite of the 000005 intent. Reset checked_at on every mark, exactly
 * like mark_changed_stock_level_for_reconciliation() already does.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION mark_inventory_reconciliation_work() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE row_data record;
            BEGIN
                IF TG_OP IN ('UPDATE', 'DELETE') THEN
                    row_data := OLD;
                    INSERT INTO inventory_reconcile_work AS w (key, branch_id, location_id, product_variant_id)
                    VALUES (row_data.branch_id || ':' || COALESCE(row_data.location_id, 0) || ':' || row_data.product_variant_id,
                        row_data.branch_id, row_data.location_id, row_data.product_variant_id)
                    ON CONFLICT (key) DO UPDATE SET revision = w.revision + 1, checked_at = NULL;
                END IF;
                IF TG_OP IN ('INSERT', 'UPDATE') THEN
                    row_data := NEW;
                    INSERT INTO inventory_reconcile_work AS w (key, branch_id, location_id, product_variant_id)
                    VALUES (row_data.branch_id || ':' || COALESCE(row_data.location_id, 0) || ':' || row_data.product_variant_id,
                        row_data.branch_id, row_data.location_id, row_data.product_variant_id)
                    ON CONFLICT (key) DO UPDATE SET revision = w.revision + 1, checked_at = NULL;
                END IF;
                RETURN NULL;
            END; $$;
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION mark_inventory_reconciliation_work() RETURNS trigger LANGUAGE plpgsql AS $$
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
            SQL);
    }
};
