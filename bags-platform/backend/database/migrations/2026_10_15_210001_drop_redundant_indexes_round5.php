<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * إزالة فهارس مكررة (L-8): تكلفة كتابة بلا فائدة قرائية.
 * - stocktake_items_unique_variant يكرر قيد الإنشاء الفريد على نفس العمودين.
 * - stock_levels_product_variant_id_index زائد لأن product_variant_id هو
 *   العمود الأول في الفهرس الفريد (variant, branch, location).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS stocktake_items_unique_variant');
        DB::statement('DROP INDEX IF EXISTS stock_levels_product_variant_id_index');
    }

    public function down(): void
    {
        DB::statement('CREATE UNIQUE INDEX stocktake_items_unique_variant ON stocktake_items (stocktake_id, product_variant_id)');
        DB::statement('CREATE INDEX stock_levels_product_variant_id_index ON stock_levels (product_variant_id)');
    }
};
