<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * F15: index supplier_payments(treasury_id) for branch-scoped
 * whereHas('treasury') lookups and FK restrict checks.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        ConcurrentIndex::ensure(
            'supplier_payments_treasury_id_index',
            'ON supplier_payments (treasury_id)',
        );
    }

    public function down(): void
    {
        ConcurrentIndex::drop('supplier_payments_treasury_id_index');
    }
};
