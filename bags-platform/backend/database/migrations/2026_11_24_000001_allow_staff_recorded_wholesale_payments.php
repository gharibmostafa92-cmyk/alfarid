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
        Schema::table('wholesale_payment_notices', function (Blueprint $table): void {
            $table->string('receipt_file_path')->nullable()->change();
            $table->boolean('staff_recorded')->default(false);
        });
    }

    public function down(): void
    {
        if (DB::table('wholesale_payment_notices')->whereNull('receipt_file_path')->exists()) {
            throw new RuntimeException('Keep recorded payments and their accounting history when rolling back.');
        }
        Schema::table('wholesale_payment_notices', function (Blueprint $table): void {
            $table->dropColumn('staff_recorded');
            $table->string('receipt_file_path')->nullable(false)->change();
        });
    }
};
