<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Voided payments must release their reference number. The original partial
 * index omitted voided_at, so reusing a reference after voiding raised a raw
 * unique violation. Rebuild the index with the same predicate the service
 * enforces: non-cash, non-null reference, and not voided.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        ConcurrentIndex::drop('supplier_payments_reference_unique');
        ConcurrentIndex::ensure(
            'supplier_payments_reference_unique',
            "ON supplier_payments (supplier_id, lower(btrim(reference_no))) WHERE reference_no IS NOT NULL AND payment_method <> 'cash' AND voided_at IS NULL",
            true,
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        ConcurrentIndex::drop('supplier_payments_reference_unique');
        ConcurrentIndex::ensure(
            'supplier_payments_reference_unique',
            "ON supplier_payments (supplier_id, lower(btrim(reference_no))) WHERE reference_no IS NOT NULL AND payment_method <> 'cash'",
            true,
        );
    }
};
