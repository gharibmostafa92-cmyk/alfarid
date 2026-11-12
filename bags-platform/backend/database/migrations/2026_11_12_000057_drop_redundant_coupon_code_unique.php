<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F-21: the case-sensitive coupons_code_unique is redundant now that the
 * case-insensitive upper(code) index exists (all lookups and creation
 * checks use upper(code)). Dropping it removes a write-tax index and the
 * weaker guarantee; the stricter upper() index remains the single guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $dependent = DB::selectOne(<<<'SQL'
            SELECT conname FROM pg_constraint
            WHERE confrelid = 'coupons'::regclass AND contype = 'f'
              AND (SELECT attnum FROM pg_attribute WHERE attrelid = 'coupons'::regclass AND attname = 'code') = ANY (confkey)
            LIMIT 1
            SQL);
        if ($dependent !== null) {
            throw new RuntimeException("Cannot drop coupons_code_unique: referenced by foreign key {$dependent->conname}.");
        }
        DB::statement('ALTER TABLE coupons DROP CONSTRAINT IF EXISTS coupons_code_unique');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_code_unique UNIQUE (code)');
    }
};
