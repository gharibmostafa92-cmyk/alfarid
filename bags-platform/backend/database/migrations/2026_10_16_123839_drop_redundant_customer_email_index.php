<?php

use App\Support\ConcurrentIndex;
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
        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");
            DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_email_unique');
        });
        ConcurrentIndex::drop('customers_email_unique');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        ConcurrentIndex::ensure('customers_email_unique', 'ON customers (email)', unique: true);
    }
};
