<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Task2 Schema Hardening:
 * - [CRITICAL-01] Drop obsolete narrow constraint treasury_transactions_type_check.
 *   The comprehensive treasury_transactions_type_sign_check (000068 / 000090)
 *   enforces all allowed types and sign invariants, including customer_receipt_reversal.
 * - [MEDIUM-02] Composite streaming indexes for ReconcileFinance window functions
 *   (PARTITION BY treasury_id ORDER BY id) and (PARTITION BY customer_id ORDER BY id).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // CRITICAL-01: drop the legacy check constraint that blocks customer_receipt_reversal
        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");
            DB::statement('ALTER TABLE treasury_transactions DROP CONSTRAINT IF EXISTS treasury_transactions_type_check');
        });

        // MEDIUM-02: composite index on (treasury_id, id) for O(1) streaming window reconciliation & latestTransaction
        ConcurrentIndex::ensure(
            'treasury_transactions_treasury_id_id_idx',
            'ON treasury_transactions (treasury_id, id)',
        );

        // MEDIUM-02: composite index on (customer_id, id) for O(1) streaming window wallet reconciliation
        ConcurrentIndex::ensure(
            'customer_wallet_transactions_customer_id_id_idx',
            'ON customer_wallet_transactions (customer_id, id)',
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        ConcurrentIndex::drop('customer_wallet_transactions_customer_id_id_idx');
        ConcurrentIndex::drop('treasury_transactions_treasury_id_id_idx');
    }
};
