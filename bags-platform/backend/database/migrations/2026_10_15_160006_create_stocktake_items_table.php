<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocktake_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stocktake_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('system_qty', 12, 4)->default(0);
            $table->decimal('counted_qty', 12, 4)->nullable();
            $table->decimal('difference', 12, 4)->nullable()->storedAs('counted_qty - system_qty');
            $table->timestamps();

            $table->index('stocktake_id');
            $table->index('product_variant_id');
            $table->unique(['stocktake_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocktake_items');
    }
};
