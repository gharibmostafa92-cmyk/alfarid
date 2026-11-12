<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * F8: profit reports resolve sale recognition by (type, reference_type,
 * reference_id) and then filter created_at. Without a composite index the
 * planner scans the whole movement history for every period.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        ConcurrentIndex::ensure(
            'stock_movements_report_lookup_index',
            'ON stock_movements (type, reference_type, reference_id, created_at)',
        );
    }

    public function down(): void
    {
        ConcurrentIndex::drop('stock_movements_report_lookup_index');
    }
};
