<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * سلامة الدفتر والترقيم (مراجعة المرتجعات والمشتريات):
 * تسلسلات أرقام المستندات الداخلية، إلغاء الدفعات بدل الحذف، قيود CHECK،
 * تفرد مرجع الرد وبريد المورد بلا حساسية حالة.
 */
return new class extends Migration
{
    /** @var array<string, array{setting: string, table: ?string, column: ?string}> */
    private const DOCUMENT_SEQUENCES = [
        'order' => ['setting' => 'order_next_number', 'table' => 'orders', 'column' => 'order_number'],
        'purchase_order' => ['setting' => 'purchase_order_next_number', 'table' => 'purchase_orders', 'column' => 'po_number'],
        'quotation' => ['setting' => 'quotation_next_number', 'table' => null, 'column' => null],
        'transfer' => ['setting' => 'transfer_next_number', 'table' => 'stock_transfers', 'column' => 'transfer_number'],
        'stocktake' => ['setting' => 'stocktake_next_number', 'table' => 'stocktakes', 'column' => 'stocktake_number'],
        'return' => ['setting' => 'return_next_number', 'table' => 'return_requests', 'column' => 'return_number'],
        'purchase_request' => ['setting' => 'purchase_request_next_number', 'table' => 'purchase_requests', 'column' => 'request_number'],
        'delivery' => ['setting' => 'delivery_next_number', 'table' => 'supplier_deliveries', 'column' => 'delivery_number'],
        'supplier_invoice' => ['setting' => 'supplier_invoice_next_number', 'table' => 'supplier_invoices', 'column' => 'invoice_number'],
        'supplier_payment' => ['setting' => 'supplier_payment_next_number', 'table' => 'supplier_payments', 'column' => 'payment_number'],
    ];

    public function up(): void
    {
        foreach (array_keys(self::DOCUMENT_SEQUENCES) as $type) {
            DB::statement("CREATE SEQUENCE IF NOT EXISTS document_{$type}_number_seq");
        }

        $caseSql = collect(self::DOCUMENT_SEQUENCES)
            ->keys()
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

        foreach (self::DOCUMENT_SEQUENCES as $type => $meta) {
            $seq = "document_{$type}_number_seq";
            $setting = $meta['setting'];
            $fromTable = '1';
            if ($meta['table'] !== null && $meta['column'] !== null) {
                $fromTable = "COALESCE((SELECT MAX((substring({$meta['column']} from '[0-9]+\$'))::bigint) + 1 FROM {$meta['table']}), 1)";
            }
            DB::statement("SELECT setval('{$seq}', GREATEST(
                COALESCE((SELECT value::bigint FROM settings WHERE key = '{$setting}'), 1000),
                {$fromTable}
            ), false)");
        }

        Schema::table('supplier_payments', function (Blueprint $table): void {
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 500)->nullable();
        });

        DB::statement('DROP INDEX IF EXISTS supplier_payments_reference_unique');
        DB::statement("CREATE UNIQUE INDEX supplier_payments_reference_unique ON supplier_payments (supplier_id, lower(btrim(reference_no))) WHERE reference_no IS NOT NULL AND payment_method <> 'cash' AND voided_at IS NULL");

        DB::statement("CREATE UNIQUE INDEX return_requests_refund_reference_unique ON return_requests (lower(btrim(refund_reference))) WHERE refund_reference IS NOT NULL AND btrim(refund_reference) <> '' AND status NOT IN ('rejected', 'cancelled')");

        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_payment_status_check');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_status_check CHECK (payment_status IN ('unpaid', 'paid', 'failed', 'refunded', 'partially_refunded'))");

        DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT purchase_order_items_qty_bounds CHECK (ordered_qty > 0 AND received_qty >= 0 AND received_qty <= ordered_qty AND unit_cost >= 0 AND line_total >= 0)');
        DB::statement('ALTER TABLE supplier_invoices ADD CONSTRAINT supplier_invoices_amounts_check CHECK (subtotal >= 0 AND tax_amount >= 0 AND paid_amount >= 0 AND paid_amount <= total_amount AND total_amount = round(subtotal + tax_amount, 2))');
        DB::statement('ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payments_amount_positive CHECK (amount > 0)');
        DB::statement('ALTER TABLE return_request_items ADD CONSTRAINT return_request_items_qty_bounds CHECK (requested_qty > 0 AND approved_qty >= 0 AND received_good_qty >= 0 AND received_damaged_qty >= 0 AND approved_qty <= requested_qty AND received_good_qty + received_damaged_qty <= approved_qty)');
        DB::statement('ALTER TABLE supplier_delivery_items ADD CONSTRAINT supplier_delivery_items_qty_bounds CHECK (shipped_qty > 0 AND received_qty >= 0 AND received_qty <= shipped_qty)');

        Schema::table('supplier_users', function (Blueprint $table): void {
            $table->dropUnique(['email']);
        });
        DB::statement('UPDATE supplier_users SET email = lower(btrim(email))');
        DB::statement('CREATE UNIQUE INDEX supplier_users_email_lower_unique ON supplier_users (lower(btrim(email)))');

        $now = now();
        DB::table('settings')->insertOrIgnore([
            ['group' => 'purchases', 'key' => 'allow_self_approve_purchases', 'value' => '0', 'type' => 'boolean', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'allow_self_approve_purchases')->delete();
        DB::statement('DROP INDEX IF EXISTS supplier_users_email_lower_unique');
        Schema::table('supplier_users', function (Blueprint $table): void {
            $table->unique('email');
        });

        DB::statement('ALTER TABLE supplier_delivery_items DROP CONSTRAINT IF EXISTS supplier_delivery_items_qty_bounds');
        DB::statement('ALTER TABLE return_request_items DROP CONSTRAINT IF EXISTS return_request_items_qty_bounds');
        DB::statement('ALTER TABLE supplier_payments DROP CONSTRAINT IF EXISTS supplier_payments_amount_positive');
        DB::statement('ALTER TABLE supplier_invoices DROP CONSTRAINT IF EXISTS supplier_invoices_amounts_check');
        DB::statement('ALTER TABLE purchase_order_items DROP CONSTRAINT IF EXISTS purchase_order_items_qty_bounds');

        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_payment_status_check');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_status_check CHECK (payment_status IN ('unpaid', 'paid', 'failed', 'refunded'))");

        DB::statement('DROP INDEX IF EXISTS return_requests_refund_reference_unique');
        DB::statement('DROP INDEX IF EXISTS supplier_payments_reference_unique');
        DB::statement("CREATE UNIQUE INDEX supplier_payments_reference_unique ON supplier_payments (supplier_id, lower(btrim(reference_no))) WHERE reference_no IS NOT NULL AND payment_method <> 'cash'");

        Schema::table('supplier_payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'void_reason']);
        });

        DB::statement('DROP FUNCTION IF EXISTS document_next_number(text, bigint)');
        foreach (array_keys(self::DOCUMENT_SEQUENCES) as $type) {
            DB::statement("DROP SEQUENCE IF EXISTS document_{$type}_number_seq");
        }
    }
};
