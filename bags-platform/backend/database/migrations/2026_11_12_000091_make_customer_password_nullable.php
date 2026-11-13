<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LOW-01 (task9): allow POS-quick-created customers to omit an unused password hash until OTP activation.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::transaction(function (): void {
                DB::statement("SET LOCAL lock_timeout = '3s'");
                DB::statement('ALTER TABLE customers ALTER COLUMN password DROP NOT NULL');
            });

            return;
        }

        Schema::table('customers', function (Blueprint $table): void {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::transaction(function (): void {
                DB::statement("SET LOCAL lock_timeout = '3s'");
                DB::statement("UPDATE customers SET password = '!' WHERE password IS NULL");
                DB::statement('ALTER TABLE customers ALTER COLUMN password SET NOT NULL');
            });

            return;
        }

        DB::table('customers')->whereNull('password')->update(['password' => '!']);
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('password')->nullable(false)->change();
        });
    }
};
