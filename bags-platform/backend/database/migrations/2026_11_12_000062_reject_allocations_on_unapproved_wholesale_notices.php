<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->installNoticeGuard(strict: true);

        $invalid = DB::scalar(<<<'SQL'
            SELECT n.id
            FROM wholesale_payment_notices n
            WHERE n.status <> 'approved'
              AND EXISTS (SELECT 1 FROM wholesale_payment_allocations a WHERE a.notice_id = n.id)
            LIMIT 1
            SQL);
        if ($invalid !== null) {
            throw new RuntimeException("Reconcile allocations on unapproved wholesale notice {$invalid}.");
        }
    }

    public function down(): void
    {
        $this->installNoticeGuard(strict: false);
    }

    private function installNoticeGuard(bool $strict): void
    {
        // Cancelled targets free approved credit, but an unapproved notice
        // cannot retain allocations even when all its target orders are cancelled.
        $inactiveNoticeCheck = $strict
            ? "(n.status <> 'approved' AND EXISTS (SELECT 1 FROM wholesale_payment_allocations a WHERE a.notice_id = n.id)) OR"
            : '';
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION wholesale_notice_overallocated(notice bigint) RETURNS boolean
            LANGUAGE sql STABLE AS \$\$
                SELECT EXISTS (
                    SELECT 1 FROM wholesale_payment_notices n
                    LEFT JOIN orders linked ON linked.id = n.order_id
                    WHERE n.id = notice AND (
                        n.amount < 0 OR n.amount::text = 'NaN' OR
                        {$inactiveNoticeCheck}
                        (SELECT coalesce(sum(a.amount), 0)
                         FROM wholesale_payment_allocations a JOIN orders target ON target.id = a.order_id
                         WHERE a.notice_id = n.id AND target.status <> 'cancelled') >
                            CASE WHEN n.status = 'approved'
                                AND (linked.id IS NULL OR linked.status <> 'cancelled')
                                THEN n.amount ELSE 0 END
                    )
                );
            \$\$;
            SQL);
    }
};
