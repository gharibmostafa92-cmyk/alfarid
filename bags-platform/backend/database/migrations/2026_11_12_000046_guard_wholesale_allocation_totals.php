<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            SET LOCAL lock_timeout = '2s';
            LOCK TABLE orders, wholesale_payment_notices, wholesale_payment_allocations IN SHARE ROW EXCLUSIVE MODE;
            CREATE OR REPLACE FUNCTION wholesale_notice_overallocated(notice bigint) RETURNS boolean
            LANGUAGE sql STABLE AS $$
                SELECT EXISTS (
                    SELECT 1 FROM wholesale_payment_notices n
                    LEFT JOIN orders linked ON linked.id = n.order_id
                    WHERE n.id = notice AND (
                        n.amount < 0 OR n.amount::text = 'NaN' OR
                        (SELECT coalesce(sum(a.amount), 0)
                         FROM wholesale_payment_allocations a JOIN orders target ON target.id = a.order_id
                         WHERE a.notice_id = n.id AND target.status <> 'cancelled') >
                            CASE WHEN n.status = 'approved' AND linked.status <> 'cancelled' THEN 0 ELSE n.amount END
                    )
                );
            $$;

            CREATE OR REPLACE FUNCTION wholesale_order_overallocated(target bigint) RETURNS boolean
            LANGUAGE sql STABLE AS $$
                SELECT EXISTS (
                    SELECT 1 FROM orders o
                    WHERE o.id = target AND o.status <> 'cancelled' AND (
                        (SELECT coalesce(sum(a.amount), 0) FROM wholesale_payment_allocations a WHERE a.order_id = o.id) +
                        (SELECT coalesce(sum(n.amount), 0) FROM wholesale_payment_notices n
                         WHERE n.order_id = o.id AND n.status = 'approved') >
                            CASE WHEN o.grand_total::text = 'NaN' THEN 0 ELSE o.grand_total END
                    )
                );
            $$;

            DO $$
            DECLARE
                invalid_id bigint;
            BEGIN
                SELECT a.id INTO invalid_id FROM wholesale_payment_allocations a WHERE a.amount::text = 'NaN' LIMIT 1;
                IF FOUND THEN
                    RAISE EXCEPTION 'Reconcile wholesale allocation % before enabling allocation caps', invalid_id;
                END IF;

                SELECT n.id INTO invalid_id FROM wholesale_payment_notices n
                WHERE wholesale_notice_overallocated(n.id) LIMIT 1;
                IF FOUND THEN
                    RAISE EXCEPTION 'Reconcile wholesale allocations for notice % before enabling allocation caps', invalid_id;
                END IF;

                SELECT o.id INTO invalid_id FROM orders o
                WHERE (EXISTS (SELECT 1 FROM wholesale_payment_allocations a WHERE a.order_id = o.id)
                    OR EXISTS (SELECT 1 FROM wholesale_payment_notices n WHERE n.order_id = o.id AND n.status = 'approved'))
                    AND wholesale_order_overallocated(o.id) LIMIT 1;
                IF FOUND THEN
                    RAISE EXCEPTION 'Reconcile wholesale allocations for order % before enabling allocation caps', invalid_id;
                END IF;
            END;
            $$;

            -- Changing the row version serializes competing writers even under REPEATABLE READ.
            -- Writing a non-capacity column avoids recursively firing the cap triggers.
            CREATE OR REPLACE FUNCTION lock_wholesale_allocation_parents() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_TABLE_NAME = 'wholesale_payment_allocations' THEN
                    UPDATE orders SET updated_at = updated_at WHERE id = NEW.order_id;
                    UPDATE wholesale_payment_notices SET updated_at = updated_at WHERE id = NEW.notice_id;
                ELSIF TG_TABLE_NAME = 'wholesale_payment_notices' THEN
                    IF NEW.status = 'approved' AND NEW.order_id IS NOT NULL THEN
                        UPDATE orders SET updated_at = updated_at WHERE id = NEW.order_id;
                    END IF;
                ELSIF OLD.status = 'cancelled' AND NEW.status <> 'cancelled' THEN
                    -- Reopening an order can consume notice credit that was freed by cancellation.
                    UPDATE wholesale_payment_notices n SET updated_at = n.updated_at
                    WHERE n.order_id = NEW.id OR EXISTS (
                        SELECT 1 FROM wholesale_payment_allocations a WHERE a.order_id = NEW.id AND a.notice_id = n.id
                    );
                END IF;
                RETURN NULL;
            END;
            $$;

            CREATE OR REPLACE FUNCTION check_wholesale_allocation_totals() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                target_id bigint;
                source_id bigint;
            BEGIN
                IF TG_TABLE_NAME = 'wholesale_payment_allocations' THEN
                    IF NEW.amount::text = 'NaN' THEN
                        RAISE check_violation USING CONSTRAINT = 'wholesale_allocations_amount_finite',
                            MESSAGE = format('Invalid amount for wholesale allocation %s', NEW.id);
                    END IF;
                    target_id := NEW.order_id;
                    source_id := NEW.notice_id;
                ELSIF TG_TABLE_NAME = 'wholesale_payment_notices' THEN
                    SELECT order_id, id INTO target_id, source_id FROM wholesale_payment_notices WHERE id = NEW.id;
                ELSE
                    target_id := NEW.id;
                    IF OLD.status = 'cancelled' AND NEW.status <> 'cancelled' THEN
                        SELECT n.id INTO source_id FROM wholesale_payment_notices n
                        WHERE (n.order_id = target_id OR EXISTS (
                            SELECT 1 FROM wholesale_payment_allocations a WHERE a.order_id = target_id AND a.notice_id = n.id
                        )) AND wholesale_notice_overallocated(n.id) LIMIT 1;
                    END IF;
                END IF;

                IF source_id IS NOT NULL AND wholesale_notice_overallocated(source_id) THEN
                    RAISE check_violation USING CONSTRAINT = 'wholesale_allocations_notice_cap',
                        MESSAGE = format('Wholesale allocations exceed available credit for notice %s', source_id);
                END IF;
                IF target_id IS NOT NULL AND wholesale_order_overallocated(target_id) THEN
                    RAISE check_violation USING CONSTRAINT = 'wholesale_allocations_order_cap',
                        MESSAGE = format('Wholesale allocations exceed the remaining balance for order %s', target_id);
                END IF;
                RETURN NULL;
            END;
            $$;

            CREATE TRIGGER wholesale_allocation_lock_parents
                AFTER INSERT OR UPDATE OF notice_id, order_id, amount ON wholesale_payment_allocations
                FOR EACH ROW EXECUTE FUNCTION lock_wholesale_allocation_parents();
            CREATE TRIGGER wholesale_notice_lock_orders
                AFTER INSERT OR UPDATE OF order_id, amount, status ON wholesale_payment_notices
                FOR EACH ROW EXECUTE FUNCTION lock_wholesale_allocation_parents();
            CREATE TRIGGER wholesale_order_lock_notices
                AFTER UPDATE OF status ON orders
                FOR EACH ROW WHEN (OLD.status = 'cancelled' AND NEW.status <> 'cancelled')
                EXECUTE FUNCTION lock_wholesale_allocation_parents();

            CREATE CONSTRAINT TRIGGER wholesale_allocation_check_totals
                AFTER INSERT OR UPDATE OF notice_id, order_id, amount ON wholesale_payment_allocations
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_wholesale_allocation_totals();
            CREATE CONSTRAINT TRIGGER wholesale_notice_check_totals
                AFTER INSERT OR UPDATE OF order_id, amount, status ON wholesale_payment_notices
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_wholesale_allocation_totals();
            CREATE CONSTRAINT TRIGGER wholesale_order_check_totals
                AFTER UPDATE OF grand_total, status ON orders
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
                WHEN (OLD.grand_total IS DISTINCT FROM NEW.grand_total OR
                    (OLD.status = 'cancelled' AND NEW.status <> 'cancelled'))
                EXECUTE FUNCTION check_wholesale_allocation_totals();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER wholesale_order_check_totals ON orders;
            DROP TRIGGER wholesale_notice_check_totals ON wholesale_payment_notices;
            DROP TRIGGER wholesale_allocation_check_totals ON wholesale_payment_allocations;
            DROP TRIGGER wholesale_order_lock_notices ON orders;
            DROP TRIGGER wholesale_notice_lock_orders ON wholesale_payment_notices;
            DROP TRIGGER wholesale_allocation_lock_parents ON wholesale_payment_allocations;
            DROP FUNCTION check_wholesale_allocation_totals();
            DROP FUNCTION lock_wholesale_allocation_parents();
            DROP FUNCTION wholesale_order_overallocated(bigint);
            DROP FUNCTION wholesale_notice_overallocated(bigint);
            SQL);
    }
};
