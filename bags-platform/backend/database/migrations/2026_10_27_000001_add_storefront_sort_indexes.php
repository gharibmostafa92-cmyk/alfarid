<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * F4: فهارس جزئية لفرز featured وbest_selling — أكثر طلبات الكتالوج
     * شيوعًا (الواجهة ترسل featured افتراضيًا) كانت تفرز كل المنتجات
     * المنشورة بلا فهرس. CONCURRENTLY حتى لا يقفل الكتابة أثناء النشر.
     */
    public function up(): void
    {
        $c = $this->concurrently();
        DB::statement("CREATE INDEX {$c}IF NOT EXISTS products_store_featured_index ON products (is_featured DESC, sales_count DESC, created_at DESC, id DESC) WHERE deleted_at IS NULL AND status = 'published' AND sales_channel IN ('retail', 'both')");
        DB::statement("CREATE INDEX {$c}IF NOT EXISTS products_store_best_selling_index ON products (sales_count DESC, id DESC) WHERE deleted_at IS NULL AND status = 'published' AND sales_channel IN ('retail', 'both')");
    }

    public function down(): void
    {
        $c = $this->concurrently();
        DB::statement("DROP INDEX {$c}IF EXISTS products_store_best_selling_index");
        DB::statement("DROP INDEX {$c}IF EXISTS products_store_featured_index");
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
