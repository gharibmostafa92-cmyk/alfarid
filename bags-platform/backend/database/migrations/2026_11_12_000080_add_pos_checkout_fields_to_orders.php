<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Cashier free-text note captured on the POS checkout screen.
            $table->string('notes', 500)->nullable()->after('shipping_notes');
            // Mandatory justification when a cashier overrides a line unit price.
            $table->string('override_reason', 255)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['notes', 'override_reason']);
        });
    }
};
