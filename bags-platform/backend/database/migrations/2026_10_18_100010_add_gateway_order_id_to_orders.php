<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the payment gateway's external order identifier to securely match signed inbound webhooks
 * and prevent transaction redirection attacks across mismatched merchant orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('gateway_order_id', 191)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['gateway_order_id']);
            $table->dropColumn('gateway_order_id');
        });
    }
};
