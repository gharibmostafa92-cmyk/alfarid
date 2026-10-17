<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * علم "يحتاج استردادًا": يُرفع عندما يصل دفع ناجح لطلب ملغي
 * (المال قُبض ولا بضاعة ستُشحن) حتى يراجعه فريق الحسابات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->boolean('requires_refund')->default(false)->after('payment_status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('requires_refund');
        });
    }
};
