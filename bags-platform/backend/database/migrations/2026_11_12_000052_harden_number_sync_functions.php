<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F-04: the runtime login held UPDATE on every numbering sequence, so a
 * compromised application could setval() a sequence back to 1 (halting all
 * checkout with unique violations) or near bigint max. Synchronization now
 * runs inside SECURITY DEFINER functions owned by the migration owner, with
 * a per-call jump cap; the runtime grant is revoked.
 */
return new class extends Migration
{
    private const SEQUENCES = <<<'SQL'
        stock_movement_number_seq,
        document_order_number_seq, document_purchase_order_number_seq, document_quotation_number_seq,
        document_transfer_number_seq, document_stocktake_number_seq, document_return_number_seq,
        document_purchase_request_number_seq, document_delivery_number_seq, document_supplier_invoice_number_seq,
        document_supplier_payment_number_seq, document_shift_number_seq, document_pos_invoice_number_seq,
        document_expense_number_seq, document_treasury_transfer_number_seq, document_payment_notice_number_seq
        SQL;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION document_next_number(doc_type text, minimum bigint DEFAULT NULL) RETURNS bigint
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
            DECLARE result bigint; seq_name text; lock_key bigint; current_value bigint;
            BEGIN
                seq_name := CASE doc_type WHEN 'order' THEN 'document_order_number_seq' WHEN 'purchase_order' THEN 'document_purchase_order_number_seq' WHEN 'quotation' THEN 'document_quotation_number_seq' WHEN 'transfer' THEN 'document_transfer_number_seq' WHEN 'stocktake' THEN 'document_stocktake_number_seq' WHEN 'return' THEN 'document_return_number_seq' WHEN 'purchase_request' THEN 'document_purchase_request_number_seq' WHEN 'delivery' THEN 'document_delivery_number_seq' WHEN 'supplier_invoice' THEN 'document_supplier_invoice_number_seq' WHEN 'supplier_payment' THEN 'document_supplier_payment_number_seq' WHEN 'shift' THEN 'document_shift_number_seq' WHEN 'pos_invoice' THEN 'document_pos_invoice_number_seq' WHEN 'expense' THEN 'document_expense_number_seq' WHEN 'treasury_transfer' THEN 'document_treasury_transfer_number_seq' WHEN 'payment_notice' THEN 'document_payment_notice_number_seq' ELSE NULL END;
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
                    EXECUTE format('SELECT last_value + CASE WHEN is_called THEN 1 ELSE 0 END FROM %I', seq_name)
                        INTO current_value;
                    -- A single sync may only fast-forward; a larger jump is an
                    -- input or breach anomaly, not legitimate catch-up.
                    IF minimum - current_value > 1000000 THEN
                        RAISE EXCEPTION 'Numbering sync jump of % exceeds the 1000000 cap for %', minimum - current_value, doc_type;
                    END IF;
                    result := GREATEST(minimum, current_value);
                    EXECUTE format('SELECT setval(%L, $1, false)', seq_name) USING result;
                END IF;
                RETURN result;
            END;
            $$;
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION inventory_movement_number(minimum bigint DEFAULT NULL) RETURNS bigint
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
            DECLARE result bigint; current_value bigint;
            BEGIN
                IF minimum IS NULL THEN
                    PERFORM pg_advisory_xact_lock_shared(7042961051501);
                    SELECT nextval('stock_movement_number_seq') INTO result;
                ELSE
                    -- A waiting exclusive lock would queue new allocations behind it.
                    IF NOT pg_try_advisory_xact_lock(7042961051501) THEN
                        RAISE EXCEPTION 'Numbering is busy; retry synchronization' USING ERRCODE = '55P03';
                    END IF;
                    SELECT last_value + CASE WHEN is_called THEN 1 ELSE 0 END
                        INTO current_value FROM stock_movement_number_seq;
                    IF minimum - current_value > 1000000 THEN
                        RAISE EXCEPTION 'Numbering sync jump of % exceeds the 1000000 cap', minimum - current_value;
                    END IF;
                    result := GREATEST(minimum, current_value);
                    PERFORM setval('stock_movement_number_seq', result, false);
                END IF;
                RETURN result;
            END;
            $$;
            SQL);
        DB::unprepared('DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = \'alfarid_app\') THEN REVOKE UPDATE ON SEQUENCE '.self::SEQUENCES.' FROM alfarid_app; END IF; END $$;');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        (require __DIR__.'/2026_11_12_000024_fail_fast_on_number_sync_contention.php')->up();
        DB::unprepared('DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = \'alfarid_app\') THEN GRANT UPDATE ON SEQUENCE '.self::SEQUENCES.' TO alfarid_app; END IF; END $$;');
    }
};
