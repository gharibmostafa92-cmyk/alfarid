<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migrates high-volume document numbering from settings table row locks to database sequences to eliminate lock contention.
 * Moves shift, pos_invoice, expense, treasury_transfer, and payment_notice to dedicated sequences to prevent commit bottlenecks.
 */
return new class extends Migration
{
    private const LEGAL_INVOICE_TYPES = ['order', 'purchase_order', 'quotation', 'transfer', 'stocktake', 'return', 'purchase_request', 'delivery', 'supplier_invoice', 'supplier_payment'];

    private const NEW_TYPES = ['shift', 'pos_invoice', 'expense', 'treasury_transfer', 'payment_notice'];

    /** @return array<string, array{setting: string, table: ?string, column: ?string}> */
    private function meta(): array
    {
        return [
            'shift' => ['setting' => 'shift_next_number', 'table' => 'pos_shifts', 'column' => 'shift_number'],
            'pos_invoice' => ['setting' => 'pos_invoice_next_number', 'table' => null, 'column' => null],
            'expense' => ['setting' => 'expense_next_number', 'table' => 'expenses', 'column' => 'expense_number'],
            'treasury_transfer' => ['setting' => 'treasury_transfer_next_number', 'table' => 'treasury_transfers', 'column' => 'transfer_number'],
            'payment_notice' => ['setting' => 'payment_notice_next_number', 'table' => 'wholesale_payment_notices', 'column' => 'notice_number'],
        ];
    }

    private function createFunction(array $types): void
    {
        $caseSql = collect($types)
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
    PERFORM pg_advisory_lock(lock_key);
    BEGIN
        IF minimum IS NULL THEN
            EXECUTE format('SELECT nextval(%L)', seq_name) INTO result;
        ELSE
            EXECUTE format('SELECT GREATEST(\$1, last_value + CASE WHEN is_called THEN 1 ELSE 0 END) FROM %I', seq_name)
                INTO result USING minimum;
            EXECUTE format('SELECT setval(%L, \$1, false)', seq_name) USING result;
        END IF;
    EXCEPTION WHEN query_canceled THEN
        PERFORM pg_advisory_unlock(lock_key);
        RAISE;
    WHEN OTHERS THEN
        PERFORM pg_advisory_unlock(lock_key);
        RAISE;
    END;
    PERFORM pg_advisory_unlock(lock_key);
    RETURN result;
END;
$$;
SQL);
    }

    public function up(): void
    {
        foreach (self::NEW_TYPES as $type) {
            DB::statement("CREATE SEQUENCE IF NOT EXISTS document_{$type}_number_seq");

            $meta = $this->meta()[$type];
            $fromTable = $meta['table'] !== null && $meta['column'] !== null
                ? "COALESCE((SELECT MAX((substring({$meta['column']} from '[0-9]+\$'))::bigint) + 1 FROM {$meta['table']}), 1)"
                : '1';
            DB::statement("SELECT setval('document_{$type}_number_seq', GREATEST(
            COALESCE((SELECT value::bigint FROM settings WHERE key = '{$meta['setting']}'), 1000),
            {$fromTable}
        ), false)");
        }

        $this->createFunction(array_merge(self::LEGAL_INVOICE_TYPES, self::NEW_TYPES));

        // Legal invoice types retain pessimistic row locking because strictly gapless contiguous numbering is legally required.
        DB::table('settings')
            ->where(function ($q): void {
                $q->whereIn('key', collect(self::NEW_TYPES)->map(fn (string $t): string => $t.'_next_number')->all());
            })
            ->update(['type' => 'integer']);
    }

    public function down(): void
    {
        $this->createFunction(self::LEGAL_INVOICE_TYPES);
        foreach (self::NEW_TYPES as $type) {
            // Persist the latest sequence value into settings before dropping sequences to prevent duplicate document numbers.
            try {
                $row = DB::selectOne("SELECT last_value, is_called FROM document_{$type}_number_seq");
                if ($row) {
                    $next = (int) $row->last_value + ($row->is_called ? 1 : 0);
                    DB::table('settings')->where('key', $this->meta()[$type]['setting'])->update(['value' => (string) max(1, $next)]);
                }
            } catch (\Throwable) {
                // Sequence does not exist; nothing to persist during rollback.
            }
            DB::statement("DROP SEQUENCE IF EXISTS document_{$type}_number_seq");
        }
    }
};
