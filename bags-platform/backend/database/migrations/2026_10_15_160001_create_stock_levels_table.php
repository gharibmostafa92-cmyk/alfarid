<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_levels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('on_hand', 12, 4)->default(0);
            $table->decimal('reserved', 12, 4)->default(0);
            $table->decimal('damaged', 12, 4)->default(0);
            $table->decimal('in_transit', 12, 4)->default(0);
            $table->decimal('reorder_level', 12, 4)->default(0);
            $table->decimal('available', 12, 4)->storedAs('on_hand - reserved - damaged');
            $table->timestamps();

            $table->index('product_variant_id');
            $table->index('branch_id');
            $table->index('location_id');
        });

        // قيد فريد مركب: ‏NULLS NOT DISTINCT عشان صفوف "من غير موقع" متتكررش (PostgreSQL 15+)
        DB::statement('CREATE UNIQUE INDEX stock_levels_variant_branch_location_unique
            ON stock_levels (product_variant_id, branch_id, location_id) NULLS NOT DISTINCT');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_levels');
    }
};
