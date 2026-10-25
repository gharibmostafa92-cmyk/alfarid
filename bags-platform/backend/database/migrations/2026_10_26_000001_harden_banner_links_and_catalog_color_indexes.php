<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table): void {
            $table->string('link_value', 2048)->nullable()->change();
        });
        DB::statement('CREATE INDEX product_variants_active_color_en ON product_variants (LOWER(color_name_en), product_id) WHERE is_active AND deleted_at IS NULL');
        DB::statement('CREATE INDEX product_variants_active_color_ar ON product_variants (color_name_ar, product_id) WHERE is_active AND deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX product_variants_active_color_en');
        DB::statement('DROP INDEX product_variants_active_color_ar');
        // Preserve valid long links when rolling back the indexes.
    }
};
