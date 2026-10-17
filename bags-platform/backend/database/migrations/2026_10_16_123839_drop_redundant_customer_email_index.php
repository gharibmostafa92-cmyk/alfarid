<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("SET lock_timeout = '2s'");
        try {
            DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_email_unique');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS customers_email_unique');
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS customers_email_unique ON customers (email)');
    }
};
