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
            DB::statement('ALTER TABLE customers ADD COLUMN IF NOT EXISTS email_verified_at timestamp(0) without time zone');
        });
        ConcurrentIndex::ensure('customers_verified_email_unique', 'ON customers (LOWER(email)) WHERE email_verified_at IS NOT NULL', unique: true);
        ConcurrentIndex::drop('customers_email_ci_unique');
        ConcurrentIndex::ensure('price_lists_active_type_index', 'ON price_lists (type) WHERE is_active');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        ConcurrentIndex::ensure('customers_email_ci_unique', 'ON customers (LOWER(email))', unique: true);
        ConcurrentIndex::drop('customers_verified_email_unique');
        ConcurrentIndex::drop('price_lists_active_type_index');
        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");
            DB::statement('ALTER TABLE customers DROP COLUMN IF EXISTS email_verified_at');
        });
    }
};
