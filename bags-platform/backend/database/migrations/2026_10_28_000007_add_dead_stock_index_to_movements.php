<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds composite index on stock movements (branch_id, product_variant_id, created_at)
 * to accelerate dead stock analysis and avoid sequential scans of the full movement history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->index(['branch_id', 'product_variant_id', 'created_at'], 'stock_movements_dead_stock_index');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropIndex('stock_movements_dead_stock_index');
        });
    }
};
