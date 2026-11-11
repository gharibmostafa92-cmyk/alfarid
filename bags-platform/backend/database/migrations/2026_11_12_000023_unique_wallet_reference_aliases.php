<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $index = DB::selectOne("SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass('wallet_transactions_reference_alias_unique')");
        if ($index?->indisvalid) {
            return;
        }
        if ($index !== null) {
            $this->down();
        }

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX CONCURRENTLY wallet_transactions_reference_alias_unique
            ON customer_wallet_transactions (
                (CASE WHEN reference_type IN ('order', 'orders', 'App\Models\Order') THEN 'order' ELSE 'return_request' END),
                reference_id,
                type
            )
            WHERE reference_type IN ('order', 'orders', 'App\Models\Order', 'return_request', 'return_requests', 'App\Models\ReturnRequest')
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS wallet_transactions_reference_alias_unique');
    }
};
