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
            // Links the return leg of an exchange to the replacement sale so a
            // retried request can return the whole settlement without duplicating it.
            $table->foreignId('exchange_order_id')->nullable()->after('return_of_order_id')
                ->constrained('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('exchange_order_id');
        });
    }
};
