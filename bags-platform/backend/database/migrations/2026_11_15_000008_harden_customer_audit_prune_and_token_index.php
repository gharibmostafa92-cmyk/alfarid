<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
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
                        ORDER BY id
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
                        REVOKE UPDATE, DELETE, TRUNCATE ON TABLE public.customer_audit_logs FROM alfarid_app;
                        GRANT EXECUTE ON FUNCTION public.alfarid_prune_customer_audit_logs(timestamptz) TO alfarid_app;
                    END IF;
                END
                $$;
                CREATE INDEX IF NOT EXISTS personal_access_tokens_tokenable_id_desc_idx
                    ON public.personal_access_tokens (tokenable_type, tokenable_id, id DESC);
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP INDEX IF EXISTS public.personal_access_tokens_tokenable_id_desc_idx;');
        }
    }
};
