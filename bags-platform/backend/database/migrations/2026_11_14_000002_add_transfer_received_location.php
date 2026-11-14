<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfer_items', function (Blueprint $table): void {
            $table->foreignId('received_location_id')->nullable()->after('to_location_id')
                ->constrained('locations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfer_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('received_location_id');
        });
    }
};
