<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION lock_credit_note_order() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                UPDATE orders SET updated_at = updated_at WHERE id = NEW.order_id;
                RETURN NULL;
            END;
            $$;
            CREATE OR REPLACE FUNCTION check_credit_note_order_total() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF wholesale_order_overallocated(NEW.order_id) THEN
                    RAISE check_violation USING CONSTRAINT = 'wholesale_allocations_order_cap',
                        MESSAGE = format('Credit notes and payments exceed order %s', NEW.order_id);
                END IF;
                RETURN NULL;
            END;
            $$;
            CREATE TRIGGER wholesale_credit_note_lock_order
                AFTER INSERT OR UPDATE OF order_id, status, refund_method, total_refund_amount ON return_requests
                FOR EACH ROW WHEN (NEW.status = 'refunded' AND NEW.refund_method = 'credit_note')
                EXECUTE FUNCTION lock_credit_note_order();
            CREATE CONSTRAINT TRIGGER wholesale_credit_note_check_total
                AFTER INSERT OR UPDATE ON return_requests DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW WHEN (NEW.status = 'refunded' AND NEW.refund_method = 'credit_note')
                EXECUTE FUNCTION check_credit_note_order_total();
            DO $$
            DECLARE invalid_order bigint;
            BEGIN
                SELECT DISTINCT order_id INTO invalid_order FROM return_requests
                WHERE status = 'refunded' AND refund_method = 'credit_note'
                  AND wholesale_order_overallocated(order_id) LIMIT 1;
                IF FOUND THEN RAISE EXCEPTION 'Reconcile credit notes for order % before enabling the guard', invalid_order; END IF;
            END;
            $$;
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;
        DB::unprepared('DROP TRIGGER IF EXISTS wholesale_credit_note_check_total ON return_requests; DROP TRIGGER IF EXISTS wholesale_credit_note_lock_order ON return_requests; DROP FUNCTION IF EXISTS check_credit_note_order_total(); DROP FUNCTION IF EXISTS lock_credit_note_order();');
    }
};
