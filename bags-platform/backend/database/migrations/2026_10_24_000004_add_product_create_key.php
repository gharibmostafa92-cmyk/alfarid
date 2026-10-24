<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * F9: product creation idempotency. The backoffice keeps the product
     * form alive across a lost response, so a retried "save" must replay
     * the existing row instead of creating a slug ...-2 duplicate.
     * Nullable so legacy rows stay untouched; Postgres treats NULLs as
     * distinct, so only real keys participate in the constraint.
     */
    public function up(): void
    {
        Schema::table('products', function ($table): void {
            $table->string('create_key', 64)->nullable()->after('created_by');
        });
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS products_creator_create_key_unique ON products (created_by, create_key) WHERE create_key IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS products_creator_create_key_unique');
        Schema::table('products', function ($table): void {
            $table->dropColumn('create_key');
        });
    }
};
