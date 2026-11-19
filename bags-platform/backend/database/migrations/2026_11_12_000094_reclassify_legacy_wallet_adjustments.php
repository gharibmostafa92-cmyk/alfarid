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
            ->whereIn('reference_type', ['wallet_adjustment', 'wallet_adjustments', 'App\Models\WalletAdjustment'])
            ->where('type', 'opening')
            ->chunkById(500, function ($rows): void {
                DB::transaction(function () use ($rows): void {
                    DB::statement("SET LOCAL lock_timeout = '5s'");
                    DB::statement("SET LOCAL app.migration_bypass_immutable = 'on'");
                    DB::table('customer_wallet_transactions')->whereIn('id', $rows->pluck('id'))
                        ->where('type', 'opening')->update(['type' => 'adjustment']);
                });
            });
    }

    public function down(): void
    {
        // A wallet adjustment must never become an opening balance again.
    }
};
