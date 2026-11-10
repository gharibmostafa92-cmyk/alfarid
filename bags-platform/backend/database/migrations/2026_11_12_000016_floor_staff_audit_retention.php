<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
/**
 * H-1: the retention floor lives inside the definer function, so a caller holding
 * the app credentials cannot pass 'infinity' and erase the audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.alfarid_prune_staff_audit_logs(cutoff timestamptz)
            RETURNS integer
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp
            AS $$
            DECLARE removed integer; effective timestamptz;
            BEGIN
                -- Enforce the default one-year retention policy even for an unrestricted cutoff.
                effective := LEAST(cutoff, now() - interval '365 days');
                DELETE FROM public.staff_audit_logs
                WHERE id IN (
                    SELECT id FROM public.staff_audit_logs
                    WHERE created_at < effective
                    ORDER BY id
                    LIMIT 1000
                );
                GET DIAGNOSTICS removed = ROW_COUNT;
                RETURN removed;
            END;
            $$;
            REVOKE ALL ON FUNCTION public.alfarid_prune_staff_audit_logs(timestamptz) FROM PUBLIC;
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'alfarid_app') THEN
                    REVOKE UPDATE, DELETE, TRUNCATE ON TABLE public.staff_audit_logs FROM alfarid_app;
                    GRANT EXECUTE ON FUNCTION public.alfarid_prune_staff_audit_logs(timestamptz) TO alfarid_app;
                END IF;
            END
            $$;
        SQL);
    }
    public function down(): void
    {
        // Intentionally keep the floor: restoring an unbounded cutoff reopens H-1.
    }
};
