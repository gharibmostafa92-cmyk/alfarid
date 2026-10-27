<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * M3: the old function took a session-level exclusive advisory lock for
     * every movement, serializing all inventory writes behind one global
     * mutex even though nextval() is concurrency-safe by itself.
     * The hot path (minimum IS NULL) now takes a transaction-scoped SHARED
     * lock — shared holders never block each other — while only the rare
     * administrative setval path takes an EXCLUSIVE transaction lock.
     */
    public function up(): void
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
        PERFORM pg_advisory_xact_lock(7042961051501);
        SELECT GREATEST(minimum, last_value + CASE WHEN is_called THEN 1 ELSE 0 END)
            INTO result FROM stock_movement_number_seq;
        PERFORM setval('stock_movement_number_seq', result, false);
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
    PERFORM pg_advisory_lock(7042961051501);
    BEGIN
        IF minimum IS NULL THEN
            SELECT nextval('stock_movement_number_seq') INTO result;
        ELSE
            SELECT GREATEST(minimum, last_value + CASE WHEN is_called THEN 1 ELSE 0 END)
                INTO result FROM stock_movement_number_seq;
            PERFORM setval('stock_movement_number_seq', result, false);
        END IF;
    EXCEPTION WHEN query_canceled THEN
        PERFORM pg_advisory_unlock(7042961051501);
        RAISE;
    WHEN OTHERS THEN
        PERFORM pg_advisory_unlock(7042961051501);
        RAISE;
    END;
    PERFORM pg_advisory_unlock(7042961051501);
    RETURN result;
END;
$$;
SQL);
    }
};
