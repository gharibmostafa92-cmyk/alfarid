<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة 9 — رصيد محفظة العميل: الاسترداد بطريقة wallet يُضاف هنا
 * (لم يكن للعملاء أي رصيد محفظة قبل هذه المرحلة).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->decimal('wallet_balance', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('wallet_balance');
        });
    }
};
