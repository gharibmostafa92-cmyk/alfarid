<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::table('customer_wallet_transactions')->select('id')
            ->where(fn ($query) => $query->whereNull('type')->orWhere('type', ''))
            ->chunkById(500, function ($rows): void {
                DB::transaction(function () use ($rows): void {
                    DB::statement("SET LOCAL lock_timeout = '5s'");
                    DB::statement("SET LOCAL app.migration_bypass_immutable = 'on'");
                    DB::update(<<<'SQL'
UPDATE customer_wallet_transactions
SET type = CASE
    WHEN reference_type IN ('order', 'orders', 'App\Models\Order') AND amount < 0 THEN 'checkout_payment'
    WHEN reference_type IN ('order', 'orders', 'App\Models\Order') THEN 'refund'
    WHEN reference_type IN ('return_request', 'return_requests', 'App\Models\ReturnRequest') THEN 'return_refund'
    WHEN reference_type IN ('customer', 'customers', 'App\Models\Customer') AND amount >= 0 THEN 'opening'
    ELSE 'adjustment'
END
WHERE id BETWEEN ? AND ? AND (type IS NULL OR type = '')
SQL, [$rows->first()->id, $rows->last()->id]);
                });
            });

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS wallet_no_truncate ON customer_wallet_transactions;
CREATE TRIGGER wallet_no_truncate
    BEFORE TRUNCATE ON customer_wallet_transactions
    FOR EACH STATEMENT EXECUTE FUNCTION prevent_wallet_mutation();
SQL);
        });
    }

    public function down(): void
    {
        // Backfill is data repair — nothing to revert.
    }
};
