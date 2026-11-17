<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateOrder = DB::table('coupon_usages')
            ->select('order_id')
            ->groupBy('order_id')
            ->havingRaw('COUNT(*) > 1')
            ->value('order_id');
        if ($duplicateOrder !== null) {
            throw new RuntimeException('coupon_usages contains duplicate order_id values; reconcile them before adding the order uniqueness constraint.');
        }

        Schema::table('coupon_usages', function (Blueprint $table): void {
            $table->string('phone_normalized', 32)->nullable()->change();
            $table->unique('order_id', 'coupon_usages_order_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('coupon_usages', function (Blueprint $table): void {
            $table->dropUnique('coupon_usages_order_id_unique');
        });

        // Keep the widened column: narrowing it could truncate valid normalized numbers.
    }
};
