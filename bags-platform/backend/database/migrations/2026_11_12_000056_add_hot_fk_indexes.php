<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F-17: foreign keys without a leading index turn every parent delete into a
 * sequential scan of the child table (and RESTRICT checks on user deletion
 * into full ledger scans). Cover the hot paths the audit named; the rest is
 * reported by db:verify-schema for query-plan review before indexing.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Cascade delete from return_requests scans the items table by parent.
        ConcurrentIndex::ensure('return_request_items_return_request_id_index', 'ON return_request_items (return_request_id)');
        // Per-customer usage limits and user-deletion RESTRICT checks look up by customer first.
        ConcurrentIndex::ensure('coupon_usages_customer_id_index', 'ON coupon_usages (customer_id)');
        // RESTRICT on user deletion scans both ledgers by author.
        ConcurrentIndex::ensure('customer_wallet_transactions_created_by_index', 'ON customer_wallet_transactions (created_by)');
        ConcurrentIndex::ensure('treasury_transactions_created_by_index', 'ON treasury_transactions (created_by)');
        // Variant lookups land on the second column of the session composite.
        ConcurrentIndex::ensure('stocktake_scans_product_variant_id_index', 'ON stocktake_scans (product_variant_id)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        ConcurrentIndex::drop('return_request_items_return_request_id_index');
        ConcurrentIndex::drop('coupon_usages_customer_id_index');
        ConcurrentIndex::drop('customer_wallet_transactions_created_by_index');
        ConcurrentIndex::drop('treasury_transactions_created_by_index');
        ConcurrentIndex::drop('stocktake_scans_product_variant_id_index');
    }
};
