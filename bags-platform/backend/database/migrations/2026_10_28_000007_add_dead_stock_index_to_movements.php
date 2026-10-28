<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M6: فهرس الركود (آخر حركة لكل صنف) — الاستعلام الفرعي في
 * ReportService::inventoryReport كان يجمع جدول الحركات كاملًا في كل طلب.
 * (branch_id, product_variant_id, created_at DESC) يغطي النطاق الفرعي
 * والعام معًا (فرع null = كل الفروع بمسح الفهرس بدل الجدول).
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
