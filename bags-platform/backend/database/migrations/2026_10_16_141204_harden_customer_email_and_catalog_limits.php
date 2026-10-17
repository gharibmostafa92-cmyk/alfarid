<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
            if (! Schema::hasColumn('customers', 'email_verified_at')) {
                Schema::table('customers', fn (Blueprint $table) => $table->timestamp('email_verified_at')->nullable());
            }
            $this->dropInvalidIndex('customers_verified_email_unique');
            DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS customers_verified_email_unique ON customers (LOWER(email)) WHERE email_verified_at IS NOT NULL');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS customers_email_ci_unique');
            $this->dropInvalidIndex('price_lists_active_type_index');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS price_lists_active_type_index ON price_lists (type) WHERE is_active');
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("SET lock_timeout = '2s'");
        try {
            $this->dropInvalidIndex('customers_email_ci_unique');
            DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS customers_email_ci_unique ON customers (LOWER(email))');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS customers_verified_email_unique');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS price_lists_active_type_index');
            Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('email_verified_at'));
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    private function dropInvalidIndex(string $name): void
    {
        $index = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$name]);
        if ($index !== null && ! $index->indisvalid) {
            DB::statement('DROP INDEX CONCURRENTLY '.$name);
        }
    }
};
