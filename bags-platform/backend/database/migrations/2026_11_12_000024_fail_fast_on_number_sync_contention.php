<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
DECLARE result bigint; seq_name text; lock_key bigint;
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
        -- A waiting exclusive lock would queue new allocations behind it.
        IF NOT pg_try_advisory_xact_lock(lock_key) THEN
            RAISE EXCEPTION 'Numbering is busy; retry synchronization' USING ERRCODE = '55P03';
        END IF;
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
DECLARE result bigint;
BEGIN
    IF minimum IS NULL THEN
        PERFORM pg_advisory_xact_lock_shared(7042961051501);
        SELECT nextval('stock_movement_number_seq') INTO result;
    ELSE
        -- A waiting exclusive lock would queue new allocations behind it.
        IF NOT pg_try_advisory_xact_lock(7042961051501) THEN
            RAISE EXCEPTION 'Numbering is busy; retry synchronization' USING ERRCODE = '55P03';
        END IF;
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
        (require __DIR__.'/2026_11_12_000018_xact_advisory_locks_for_numbering.php')->up();
    }
};
