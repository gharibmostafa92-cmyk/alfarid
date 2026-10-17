<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * توحيد أطوال الحقول مع التحقق (M1): التحقق كان يسمح بـ 500 حرف بينما
 * الأعمدة varchar(255) — فأي قيمة بين 256 و500 كانت تنهار بـ 22001/500.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('shipping_address_line', 500)->nullable()->change();
            $table->string('shipping_notes', 500)->nullable()->change();
        });
        Schema::table('order_status_histories', function (Blueprint $table): void {
            $table->string('notes', 500)->nullable()->change();
        });
        Schema::table('shipping_companies', function (Blueprint $table): void {
            $table->string('tracking_url_template', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('shipping_address_line')->nullable()->change();
            $table->string('shipping_notes')->nullable()->change();
        });
        Schema::table('order_status_histories', function (Blueprint $table): void {
            $table->string('notes')->nullable()->change();
        });
        Schema::table('shipping_companies', function (Blueprint $table): void {
            $table->string('tracking_url_template')->nullable()->change();
        });
    }
};
