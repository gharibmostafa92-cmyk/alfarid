<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('catalog_min_retail_price', 14, 2)->nullable();
            $table->timestampTz('catalog_price_refreshed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['catalog_min_retail_price', 'catalog_price_refreshed_at']));
    }
};
