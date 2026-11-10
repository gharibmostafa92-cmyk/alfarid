<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
/**
 * H-4: session-level advisory locks could leak into a pooled PgBouncer connection on
 * cancel. Transaction-level locks are released on COMMIT/ROLLBACK and cannot leak.
 * The minimum branch synchronizes the next number without issuing it. Only the
 * no-argument branch allocates a document or movement number.
 */
return new class extends Migration
{
    private const TYPES = [
        'order', 'purchase_order', 'quotation', 'transfer', 'stocktake', 'return', 'purchase_request',
        'delivery', 'supplier_invoice', 'supplier_payment',
        'shift', 'pos_invoice', 'expense', 'treasury_transfer', 'payment_notice',
    ];
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $caseSql = collect(self::TYPES)
            ->map(fn (string $type): string => "WHEN '{$type}' THEN 'document_{$type}_number_seq'")
            ->implode(' ');
        DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION document_next_number(doc_type text, minimum bigint DEFAULT NULL) RETURNS bigint
LANGUAGE plpgsql AS $$
DECLARE result bigint; seq_name text; lock_key bigint; prior_timeout text;
BEGIN
    seq_name := CASE doc_type {$caseSql} ELSE NULL END;
    IF seq_name IS NULL THEN
        RAISE EXCEPTION 'Unknown document type: %', doc_type;
    END IF;
    lock_key := hashtext('document-number:' || doc_type);
    IF minimum IS NULL THEN
        -- nextval is atomic; the shared lock only waits out a concurrent setval.
        PERFORM pg_advisory_xact_lock_shared(lock_key);
        EXECUTE format('SELECT nextval(%L)', seq_name) INTO result;
    ELSE
        prior_timeout := current_setting('lock_timeout');
        PERFORM set_config('lock_timeout', '2s', true);
        PERFORM pg_advisory_xact_lock(lock_key);
        PERFORM set_config('lock_timeout', prior_timeout, true);
        EXECUTE format('SELECT GREATEST($1, last_value + CASE WHEN is_called THEN 1 ELSE 0 END) FROM %I', seq_name)
            INTO result USING minimum;
        EXECUTE format('SELECT setval(%L, $1, false)', seq_name) USING result;
    END IF;
    RETURN result;
END;
$$;
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION inventory_movement_number(minimum bigint DEFAULT NULL) RETURNS bigint
LANGUAGE plpgsql AS $$
DECLARE result bigint; prior_timeout text;
BEGIN
    IF minimum IS NULL THEN
        PERFORM pg_advisory_xact_lock_shared(7042961051501);
        SELECT nextval('stock_movement_number_seq') INTO result;
    ELSE
        prior_timeout := current_setting('lock_timeout');
        PERFORM set_config('lock_timeout', '2s', true);
        PERFORM pg_advisory_xact_lock(7042961051501);
        PERFORM set_config('lock_timeout', prior_timeout, true);
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
        // Do not restore the session-lock versions: they reintroduce the pooled-lock leak.
    }
};
