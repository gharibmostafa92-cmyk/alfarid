<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The append-only trigger blocks even data repair, so it is dropped,
        // the legacy rows are backfilled, then the trigger is recreated.
        // Legacy rows carry empty types — map them from reference and sign so
        // labels unify everywhere (R11, store wallet, admin wallet).
        DB::statement('DROP TRIGGER IF EXISTS wallet_immutable ON customer_wallet_transactions');
        DB::statement(<<<'SQL'
UPDATE customer_wallet_transactions
SET type = CASE
    WHEN reference_type IN ('order', 'App\Models\Order') AND amount < 0 THEN 'checkout_payment'
    WHEN reference_type IN ('order', 'App\Models\Order') THEN 'refund'
    WHEN reference_type IN ('return_request', 'App\Models\ReturnRequest') THEN 'return_refund'
    WHEN amount >= 0 THEN 'opening'
    ELSE 'adjustment'
END
WHERE type IS NULL OR type = ''
SQL);
        DB::unprepared("CREATE OR REPLACE FUNCTION prevent_wallet_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Wallet ledger is append-only'; END; $$; CREATE TRIGGER wallet_immutable BEFORE UPDATE OR DELETE ON customer_wallet_transactions FOR EACH ROW EXECUTE FUNCTION prevent_wallet_mutation()");
    }

    public function down(): void
    {
        // Backfill is data repair — nothing to revert.
    }
};
