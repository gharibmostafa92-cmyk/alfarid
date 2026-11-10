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
        // Revoke in dependency order: database/schema ACLs first, then object
        // grants, then memberships — otherwise DROP ROLE fails with 2BP01.
        DB::unprepared("REVOKE CONNECT ON DATABASE {$db} FROM alfarid_app, alfarid_monitor");
        DB::unprepared(<<<'SQL'
            REVOKE ALL ON SCHEMA public FROM alfarid_app, alfarid_monitor;
            ALTER DEFAULT PRIVILEGES FOR ROLE CURRENT_USER IN SCHEMA public REVOKE ALL ON TABLES FROM alfarid_app;
            ALTER DEFAULT PRIVILEGES FOR ROLE CURRENT_USER IN SCHEMA public REVOKE ALL ON SEQUENCES FROM alfarid_app;
            ALTER DEFAULT PRIVILEGES FOR ROLE CURRENT_USER IN SCHEMA public REVOKE ALL ON TABLES FROM alfarid_monitor;
            REVOKE ALL ON ALL TABLES IN SCHEMA public FROM alfarid_app, alfarid_monitor;
            REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM alfarid_app, alfarid_monitor;
            REVOKE pg_monitor FROM alfarid_monitor;
        SQL);
        // Roles are cluster-global but this migration runs per-database. A
        // shared local cluster (e.g. alfarid_local + alfarid_testing) may hold
        // grants on a sibling database, which blocks DROP ROLE with 2BP01.
        // Drop when possible; otherwise leave the now-privilege-less role and
        // report instead of failing rollback (up() recreates idempotently).
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                BEGIN
                    DROP ROLE IF EXISTS alfarid_monitor;
                EXCEPTION WHEN dependent_objects_still_exist THEN
                    RAISE NOTICE 'alfarid_monitor kept: dependencies remain on another database';
                END;
                BEGIN
                    DROP ROLE IF EXISTS alfarid_app;
                EXCEPTION WHEN dependent_objects_still_exist THEN
                    RAISE NOTICE 'alfarid_app kept: dependencies remain on another database';
                END;
            END
            $$;
        SQL);
    }
};
