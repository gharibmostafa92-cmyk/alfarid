<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_list_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->decimal('min_qty', 12, 4)->default(1);
            $table->decimal('price', 12, 4);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['product_variant_id', 'min_qty']);
            $table->unique(['price_list_id', 'product_variant_id', 'min_qty']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_items');
    }
};
