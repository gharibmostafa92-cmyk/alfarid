<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مفتاح منع تكرار الشراء (F-13): إعادة إرسال العميل بعد انقطاع الشبكة
 * ترجع الطلب نفسه بدل «السلة فارغة» ثم طلب مكرر. القيد الفريد على
 * مستوى القاعدة، وNULLات متعددة مسموحة في PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('idempotency_key', 191)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
