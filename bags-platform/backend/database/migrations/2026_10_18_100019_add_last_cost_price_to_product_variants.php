<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة 10 — آخر سعر شراء فعلي للمتغير: يكتبه استلام أوامر الشراء
 * بجانب متوسط التكلفة المرجح (cost_price).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->decimal('last_cost_price', 12, 4)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn('last_cost_price');
        });
    }
};
