<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $concurrently = DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
            DB::statement(
                "CREATE INDEX {$concurrently}IF NOT EXISTS customer_audit_logs_prunable_created_at_idx "
                .'ON public.customer_audit_logs (created_at, id) '
                ."WHERE action IN ('login_failed', 'login_succeeded', 'token_issued')"
            );

            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION public.alfarid_prune_customer_audit_logs(cutoff timestamptz)
                RETURNS integer
                LANGUAGE plpgsql
                SECURITY DEFINER
                SET search_path = pg_catalog, pg_temp
                AS $$
                DECLARE
                    removed integer;
                    effective timestamptz;
                BEGIN
                    effective := LEAST(cutoff, now() - interval '90 days');
                    DELETE FROM public.customer_audit_logs
                    WHERE id IN (
                        SELECT id
                        FROM public.customer_audit_logs
                        WHERE created_at < effective
                          AND action IN ('login_failed', 'login_succeeded', 'token_issued')
                        ORDER BY created_at, id
                        LIMIT 1000
                    );
                    GET DIAGNOSTICS removed = ROW_COUNT;
                    RETURN removed;
                END;
                $$;
                REVOKE ALL ON FUNCTION public.alfarid_prune_customer_audit_logs(timestamptz) FROM PUBLIC;
                DO $$
                BEGIN
                    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'alfarid_app') THEN
                        GRANT EXECUTE ON FUNCTION public.alfarid_prune_customer_audit_logs(timestamptz) TO alfarid_app;
                    END IF;
                END
                $$;
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $concurrently = DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
            DB::statement("DROP INDEX {$concurrently}IF EXISTS public.customer_audit_logs_prunable_created_at_idx");
        }
    }
};
