<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku')->unique();
            $table->string('barcode')->nullable()->unique();
            $table->string('color_name_ar')->nullable();
            $table->string('color_name_en')->nullable();
            $table->string('color_hex', 7)->nullable();            // #000000
            $table->string('size')->nullable();                     // S, M, L, 20 inch
            $table->string('capacity')->nullable();                 // 40L
            $table->decimal('cost_price', 12, 2)->default(0);
            $table->decimal('retail_price', 12, 2)->nullable();
            $table->decimal('wholesale_price', 12, 2)->nullable();
            $table->unsignedInteger('reorder_level')->default(0);
            $table->decimal('weight_kg', 8, 3)->nullable();         // لو فاضي ياخد وزن المنتج
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
