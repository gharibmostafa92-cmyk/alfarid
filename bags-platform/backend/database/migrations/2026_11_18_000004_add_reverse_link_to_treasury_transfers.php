<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treasury_transfers', function (Blueprint $table): void {
            $table->foreignId('reverses_transfer_id')
                ->nullable()
                ->unique()
                ->constrained('treasury_transfers')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('treasury_transfers', function (Blueprint $table): void {
            $table->dropForeign(['reverses_transfer_id']);
            $table->dropUnique(['reverses_transfer_id']);
            $table->dropColumn('reverses_transfer_id');
        });
    }
};
