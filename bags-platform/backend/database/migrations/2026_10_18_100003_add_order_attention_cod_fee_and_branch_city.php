<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds operational attention flags, cash-on-delivery fees, and branch city linkage:
 * - orders.needs_attention: flags orders requiring manual staff intervention.
 * - orders.cod_fee: cash on delivery surcharge collected from customer.
 * - branches.city_id: links branches to normalized city entities without hard FK constraints.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->boolean('needs_attention')->default(false);
            $table->decimal('cod_fee', 12, 2)->default(0);
        });
        Schema::table('branches', function (Blueprint $table): void {
            $table->unsignedBigInteger('city_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['needs_attention', 'cod_fee']);
        });
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn('city_id');
        });
    }
};
