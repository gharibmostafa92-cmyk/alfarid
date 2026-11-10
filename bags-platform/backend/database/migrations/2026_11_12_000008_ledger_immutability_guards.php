<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * F3: treasury ledger is append-only (mirrors prevent_wallet_mutation).
     * F25: TRUNCATE on append-only ledgers must fail like UPDATE/DELETE.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_treasury_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Treasury ledger is append-only';
            END;
            $$;
            DROP TRIGGER IF EXISTS treasury_transactions_immutable ON treasury_transactions;
            CREATE TRIGGER treasury_transactions_immutable
                BEFORE UPDATE OR DELETE ON treasury_transactions
                FOR EACH ROW EXECUTE FUNCTION prevent_treasury_mutation();
            DROP TRIGGER IF EXISTS treasury_transactions_no_truncate ON treasury_transactions;
            CREATE TRIGGER treasury_transactions_no_truncate
                BEFORE TRUNCATE ON treasury_transactions
                FOR EACH STATEMENT EXECUTE FUNCTION prevent_treasury_mutation();
            DROP TRIGGER IF EXISTS wallet_no_truncate ON customer_wallet_transactions;
            CREATE TRIGGER wallet_no_truncate
                BEFORE TRUNCATE ON customer_wallet_transactions
                FOR EACH STATEMENT EXECUTE FUNCTION prevent_wallet_mutation();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS treasury_transactions_no_truncate ON treasury_transactions');
        DB::statement('DROP TRIGGER IF EXISTS treasury_transactions_immutable ON treasury_transactions');
        DB::statement('DROP FUNCTION IF EXISTS prevent_treasury_mutation()');
        DB::statement('DROP TRIGGER IF EXISTS wallet_no_truncate ON customer_wallet_transactions');
    }
};
