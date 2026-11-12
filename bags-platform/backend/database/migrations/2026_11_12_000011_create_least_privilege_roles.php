<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * C2 (stage 1): create least-privilege roles. Cutover (app login role,
     * PgBouncer users, passwords) stays a staged runbook step in
     * docs/TASK2_DEPLOYMENT_STATUS.md — no login credentials are minted here.
     */
    public function up(): void
    {
        // Driver guard: PostgreSQL-only DDL (roles, pg_index); a no-op on other drivers such as SQLite tests.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $db = '"' . str_replace('"', '""', DB::getDatabaseName()) . '"';
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'alfarid_app') THEN
                    CREATE ROLE alfarid_app WITH NOLOGIN;
                END IF;
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'alfarid_monitor') THEN
                    CREATE ROLE alfarid_monitor WITH NOLOGIN;
                END IF;
            END
            $$;
        SQL);
        DB::unprepared("GRANT CONNECT ON DATABASE {$db} TO alfarid_app, alfarid_monitor");
        DB::unprepared(<<<'SQL'
            GRANT USAGE ON SCHEMA public TO alfarid_app, alfarid_monitor;
            GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO alfarid_app;
            GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO alfarid_app;
            GRANT pg_monitor TO alfarid_monitor;
            ALTER DEFAULT PRIVILEGES FOR ROLE CURRENT_USER IN SCHEMA public
                GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO alfarid_app;
            ALTER DEFAULT PRIVILEGES FOR ROLE CURRENT_USER IN SCHEMA public
                GRANT USAGE, SELECT ON SEQUENCES TO alfarid_app;
            ALTER DEFAULT PRIVILEGES FOR ROLE CURRENT_USER IN SCHEMA public
                GRANT SELECT ON TABLES TO alfarid_monitor;
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $db = '"' . str_replace('"', '""', DB::getDatabaseName()) . '"';
        // Roll back database-local ACLs. The roles and pg_monitor membership
        // belong to the cluster and may be in use by other databases.
        DB::unprepared("REVOKE CONNECT ON DATABASE {$db} FROM alfarid_app, alfarid_monitor");
        DB::unprepared(<<<'SQL'
            REVOKE ALL ON SCHEMA public FROM alfarid_app, alfarid_monitor;
            ALTER DEFAULT PRIVILEGES FOR ROLE CURRENT_USER IN SCHEMA public REVOKE ALL ON TABLES FROM alfarid_app;
            ALTER DEFAULT PRIVILEGES FOR ROLE CURRENT_USER IN SCHEMA public REVOKE ALL ON SEQUENCES FROM alfarid_app;
            ALTER DEFAULT PRIVILEGES FOR ROLE CURRENT_USER IN SCHEMA public REVOKE ALL ON TABLES FROM alfarid_monitor;
            REVOKE ALL ON ALL TABLES IN SCHEMA public FROM alfarid_app, alfarid_monitor;
            REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM alfarid_app, alfarid_monitor;
        SQL);
    }
};
