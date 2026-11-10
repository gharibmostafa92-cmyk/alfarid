<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * F1/F4: the admin sync path holds a session-level exclusive advisory lock.
     * - Catch query_canceled (statement_timeout / client cancel) explicitly:
     *   OTHERS does not catch 57014, so a cancel between lock and unlock leaked
     *   the session lock into a pooled PgBouncer connection and froze all
     *   inventory writes until server_lifetime recycled it.
     * - Bound the wait for the lock itself to 2s via a transaction-local
     *   lock_timeout that is restored afterwards, so a stalled holder fails
     *   this rare admin call with "busy, retry" instead of queueing every
     *   hot-path writer behind it (F4).
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION inventory_movement_number(minimum bigint DEFAULT NULL) RETURNS bigint
LANGUAGE plpgsql AS $$
DECLARE result bigint; prior_timeout text;
BEGIN
    IF minimum IS NULL THEN
        PERFORM pg_advisory_xact_lock_shared(7042961051501);
        SELECT nextval('stock_movement_number_seq') INTO result;
    ELSE
        SELECT current_setting('lock_timeout') INTO prior_timeout;
        PERFORM set_config('lock_timeout', '2s', true);
        PERFORM pg_advisory_lock(7042961051501);
        PERFORM set_config('lock_timeout', prior_timeout, true);
        BEGIN
            SELECT GREATEST(minimum, last_value + CASE WHEN is_called THEN 1 ELSE 0 END)
                INTO result FROM stock_movement_number_seq;
            PERFORM setval('stock_movement_number_seq', result, false);
        EXCEPTION WHEN query_canceled OR OTHERS THEN
            PERFORM pg_advisory_unlock(7042961051501);
            RAISE;
        END;
        PERFORM pg_advisory_unlock(7042961051501);
    END IF;
    RETURN result;
END;
$$;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION inventory_movement_number(minimum bigint DEFAULT NULL) RETURNS bigint
LANGUAGE plpgsql AS $$
DECLARE result bigint;
BEGIN
    IF minimum IS NULL THEN
        PERFORM pg_advisory_xact_lock_shared(7042961051501);
        SELECT nextval('stock_movement_number_seq') INTO result;
    ELSE
        PERFORM pg_advisory_lock(7042961051501);
        BEGIN
            SELECT GREATEST(minimum, last_value + CASE WHEN is_called THEN 1 ELSE 0 END)
                INTO result FROM stock_movement_number_seq;
            PERFORM setval('stock_movement_number_seq', result, false);
        EXCEPTION WHEN OTHERS THEN
            PERFORM pg_advisory_unlock(7042961051501);
            RAISE;
        END;
        PERFORM pg_advisory_unlock(7042961051501);
    END IF;
    RETURN result;
END;
$$;
SQL);
    }
};
