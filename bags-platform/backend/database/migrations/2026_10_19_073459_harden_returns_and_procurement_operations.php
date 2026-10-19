<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // نسخ مرفقات المرتجعات من القرص العام إلى الخاص يُنفَّذ بأمر
        // returns:privatize-attachments خارج هذه المعاملة حتى لا يطيل قفل الهجرة.
        DB::statement('ALTER TABLE product_variants ALTER COLUMN cost_price TYPE numeric(14, 4)');
        Schema::create('operation_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('operation', 100);
            $table->string('actor_type', 100);
            $table->unsignedBigInteger('actor_id');
            $table->string('key', 128);
            $table->string('request_hash', 64);
            $table->string('result_type')->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->timestamp('created_at');
            $table->unique(['operation', 'actor_type', 'actor_id', 'key'], 'operation_idempotency_scope_unique');
        });
        Schema::table('supplier_payments', function (Blueprint $table): void {
            $table->string('idempotency_key', 128)->nullable()->unique();
        });
        Schema::table('return_request_items', function (Blueprint $table): void {
            $table->decimal('refund_amount', 12, 2)->default(0);
        });
        DB::statement('UPDATE return_request_items SET refund_amount = round((received_good_qty + received_damaged_qty) * unit_refund_price, 2)');
        Schema::create('customer_wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->decimal('amount', 14, 2);
            $table->decimal('balance_after', 14, 2);
            $table->string('reference_type');
            $table->unsignedBigInteger('reference_id');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['reference_type', 'reference_id', 'type'], 'wallet_reference_unique');
            $table->index(['customer_id', 'created_at']);
        });
        DB::statement("INSERT INTO customer_wallet_transactions (customer_id, type, amount, balance_after, reference_type, reference_id) SELECT id, 'opening', wallet_balance, wallet_balance, 'customer', id FROM customers WHERE wallet_balance <> 0");
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_wallet_nonnegative CHECK (wallet_balance >= 0)');
        DB::statement('ALTER TABLE customer_wallet_transactions ADD CONSTRAINT wallet_balance_nonnegative CHECK (balance_after >= 0)');
        DB::unprepared("CREATE OR REPLACE FUNCTION prevent_wallet_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Wallet ledger is append-only'; END; $$; CREATE TRIGGER wallet_immutable BEFORE UPDATE OR DELETE ON customer_wallet_transactions FOR EACH ROW EXECUTE FUNCTION prevent_wallet_mutation()");
        DB::statement("CREATE UNIQUE INDEX supplier_payments_reference_unique ON supplier_payments (supplier_id, lower(btrim(reference_no))) WHERE reference_no IS NOT NULL AND payment_method <> 'cash'");
        DB::statement("CREATE UNIQUE INDEX supplier_invoices_supplier_no_unique ON supplier_invoices (supplier_id, lower(btrim(supplier_invoice_no))) WHERE status <> 'cancelled'");
        DB::statement('CREATE INDEX supplier_invoices_supplier_date_index ON supplier_invoices (supplier_id, issue_date)');
        DB::statement('CREATE INDEX return_requests_number_pattern_index ON return_requests USING gin (return_number gin_trgm_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS return_requests_number_pattern_index');
        DB::statement('DROP INDEX IF EXISTS supplier_invoices_supplier_date_index');
        DB::statement('DROP INDEX IF EXISTS supplier_invoices_supplier_no_unique');
        DB::statement('DROP INDEX IF EXISTS supplier_payments_reference_unique');
        Schema::dropIfExists('customer_wallet_transactions');
        DB::statement('DROP FUNCTION IF EXISTS prevent_wallet_mutation()');
        DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_wallet_nonnegative');
        Schema::table('supplier_payments', function (Blueprint $table): void {
            $table->dropColumn('idempotency_key');
        });
        Schema::table('return_request_items', function (Blueprint $table): void {
            $table->dropColumn('refund_amount');
        });
        Schema::dropIfExists('operation_idempotency_keys');
        // لا نُرجع cost_price إلى numeric(12,2): التخفيض يفقد الدقة بصمت.
    }
};
