<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::selectOne("SELECT 1 FROM pg_constraint WHERE conrelid = 'products'::regclass AND conname = 'products_occasions_valid'") === null) {
            DB::statement("ALTER TABLE products ADD CONSTRAINT products_occasions_valid CHECK (occasions IS NULL OR (jsonb_typeof(occasions) = 'array' AND occasions <@ '[\"casual\",\"work\",\"weekend\",\"evening\"]'::jsonb)) NOT VALID");
        }
        DB::statement('ALTER TABLE products VALIDATE CONSTRAINT products_occasions_valid');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_occasions_valid');
    }
};
