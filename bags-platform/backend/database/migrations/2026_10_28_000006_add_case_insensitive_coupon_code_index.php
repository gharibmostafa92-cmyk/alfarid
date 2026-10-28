<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX coupons_code_upper_unique ON coupons (upper(code))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS coupons_code_upper_unique');
    }
};
