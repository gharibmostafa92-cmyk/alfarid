<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::table('customers')->whereNotNull('email')->selectRaw('LOWER(TRIM(email))')
            ->groupByRaw('LOWER(TRIM(email))')->havingRaw('COUNT(*) > 1')->exists()
            || DB::table('customers')->selectRaw('TRIM(phone)')->groupByRaw('TRIM(phone)')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Resolve duplicate customer identities before normalizing them.');
        }
        DB::statement('UPDATE customers SET email = LOWER(TRIM(email)), phone = TRIM(phone)');
        DB::statement('CREATE UNIQUE INDEX customers_email_ci_unique ON customers (LOWER(email))');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement("ALTER TABLE customers ADD COLUMN search_text TEXT GENERATED ALWAYS AS (
            COALESCE(name, '') || ' ' || COALESCE(phone, '') || ' ' || COALESCE(email, '') || ' ' ||
            COALESCE(commercial_register, '') || ' ' || COALESCE(company_name, '') || ' ' || COALESCE(tax_number, '')
        ) STORED");
        DB::statement('CREATE INDEX customers_search_trgm_index ON customers USING GIN (search_text gin_trgm_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS customers_email_ci_unique');
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('search_text'));
    }
};
