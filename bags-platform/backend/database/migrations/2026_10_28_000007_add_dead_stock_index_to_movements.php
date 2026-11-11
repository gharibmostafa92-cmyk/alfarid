<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds composite index on stock movements (branch_id, product_variant_id, created_at)
 * to accelerate dead stock analysis and avoid sequential scans of the full movement history.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        ConcurrentIndex::ensure('stock_movements_dead_stock_index', 'ON stock_movements (branch_id, product_variant_id, created_at)');
    }

    public function down(): void
    {
        ConcurrentIndex::drop('stock_movements_dead_stock_index');
    }
};
