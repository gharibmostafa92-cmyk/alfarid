<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Shorten the exclusive movement-number sync lock to the statement duration.
     *
     * The admin sync path (minimum given) used a transaction-scoped exclusive
     * advisory lock, held until the outer transaction ended — stalling every
     * concurrent inventory write for the whole settings edit. It now takes a
     * session-scoped exclusive lock and releases it in the same statement
     * (exception-safe), mirroring document_next_number. The setval itself is
     * unchanged and still happens synchronously, so numbering semantics and
     * in-transaction visibility are preserved; only the blocking window shrinks
     * from "whole transaction" to microseconds.
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
};
