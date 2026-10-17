<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سلة واحدة لكل عميل مسجل (M6): بلا هذا القيد، ‏firstOrCreate تحت التزامن
 * ينشئ سلتين للعميل وتتوزع البنود بينهما. ‏NULLs متعددة مسموحة (سلال الزوار).
 * ملحوظة إنتاج: تُدمج أي سلال مكررة موجودة قبل تطبيق القيد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->unique('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->dropUnique(['customer_id']);
        });
    }
};
