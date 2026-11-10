<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * task3 L7: the application role may append audit rows, not rewrite or erase
 * them. Retention deletion goes through a definer function so a compromised
 * app login cannot choose which rows disappear.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION alfarid_prune_staff_audit_logs(cutoff timestamptz)
            RETURNS integer
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public
            AS $$
            DECLARE removed integer;
            BEGIN
                DELETE FROM staff_audit_logs
                WHERE id IN (
                    SELECT id FROM staff_audit_logs
                    WHERE created_at < cutoff
                    ORDER BY id
                    LIMIT 1000
                );
                GET DIAGNOSTICS removed = ROW_COUNT;
                RETURN removed;
            END;
            $$;
            REVOKE ALL ON FUNCTION alfarid_prune_staff_audit_logs(timestamptz) FROM PUBLIC;
        SQL);
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'alfarid_app') THEN
                    REVOKE UPDATE, DELETE ON TABLE staff_audit_logs FROM alfarid_app;
                    GRANT EXECUTE ON FUNCTION alfarid_prune_staff_audit_logs(timestamptz) TO alfarid_app;
                END IF;
            END
            $$;
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'alfarid_app') THEN
                    GRANT UPDATE, DELETE ON TABLE staff_audit_logs TO alfarid_app;
                END IF;
            END
            $$;
            DROP FUNCTION IF EXISTS alfarid_prune_staff_audit_logs(timestamptz);
        SQL);
    }
};
