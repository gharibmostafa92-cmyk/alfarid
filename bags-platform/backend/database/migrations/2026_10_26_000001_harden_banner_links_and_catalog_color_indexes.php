<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // F11: CONCURRENTLY — فهارس الألوان على جدول يكتب فيه المخزون والطلبات.
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table): void {
            $table->string('link_value', 2048)->nullable()->change();
        });
        DB::statement('CREATE INDEX '.$this->concurrently().'IF NOT EXISTS product_variants_active_color_en ON product_variants (LOWER(color_name_en), product_id) WHERE is_active AND deleted_at IS NULL');
        DB::statement('CREATE INDEX '.$this->concurrently().'IF NOT EXISTS product_variants_active_color_ar ON product_variants (color_name_ar, product_id) WHERE is_active AND deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX '.$this->concurrently().'IF EXISTS product_variants_active_color_en');
        DB::statement('DROP INDEX '.$this->concurrently().'IF EXISTS product_variants_active_color_ar');
        // Preserve valid long links when rolling back the indexes.
    }

    /** F11: CONCURRENTLY في النشر فقط — انظر هجرة حقول الواجهة. */
    private function concurrently(): string
    {
        if (app()->environment('testing') || DB::transactionLevel() > 0) {
            return '';
        }

        return 'CONCURRENTLY ';
    }
};
