<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION wholesale_order_overallocated(target bigint) RETURNS boolean
            LANGUAGE sql STABLE AS $$
                SELECT EXISTS (
                    SELECT 1 FROM orders o
                    WHERE o.id = target AND o.status <> 'cancelled' AND (
                        (SELECT coalesce(sum(a.amount), 0) FROM wholesale_payment_allocations a
                         JOIN wholesale_payment_notices sn ON sn.id = a.notice_id
                         WHERE a.order_id = o.id AND sn.status = 'approved') +
                        (SELECT coalesce(sum(n.amount), 0) FROM wholesale_payment_notices n
                         WHERE n.order_id = o.id AND n.status = 'approved') +
                        (SELECT coalesce(sum(r.total_refund_amount), 0) FROM return_requests r
                         WHERE r.order_id = o.id AND r.status = 'refunded' AND r.refund_method = 'credit_note') >
                            CASE WHEN o.grand_total::text = 'NaN' THEN 0 ELSE o.grand_total END
                    )
                );
            $$;
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION wholesale_order_overallocated(target bigint) RETURNS boolean
            LANGUAGE sql STABLE AS $$
                SELECT EXISTS (
                    SELECT 1 FROM orders o
                    WHERE o.id = target AND o.status <> 'cancelled' AND (
                        (SELECT coalesce(sum(a.amount), 0) FROM wholesale_payment_allocations a
                         JOIN wholesale_payment_notices sn ON sn.id = a.notice_id
                         WHERE a.order_id = o.id AND sn.status = 'approved') +
                        (SELECT coalesce(sum(n.amount), 0) FROM wholesale_payment_notices n
                         WHERE n.order_id = o.id AND n.status = 'approved') >
                            CASE WHEN o.grand_total::text = 'NaN' THEN 0 ELSE o.grand_total END
                    )
                );
            $$;
        SQL);
    }
};
