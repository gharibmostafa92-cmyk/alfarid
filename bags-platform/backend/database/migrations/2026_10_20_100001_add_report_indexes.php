<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes to accelerate financial reporting: supplier invoice aging queries and stock movement COGS date aggregations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table): void {
            $table->index(['supplier_id', 'status'], 'supplier_invoices_supplier_status_index');
            $table->index('due_date', 'supplier_invoices_due_date_index');
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->index(['type', 'created_at'], 'stock_movements_type_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table): void {
            $table->dropIndex('supplier_invoices_supplier_status_index');
            $table->dropIndex('supplier_invoices_due_date_index');
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropIndex('stock_movements_type_created_index');
        });
    }
};
