<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        foreach ($this->indexes() as $name => $definition) {
            $this->ensureIndex($name, $definition);
        }
    }

    public function down(): void
    {
        $this->dropIndex('products_catalog_retail_price_index');
        $this->dropIndex('products_occasions_gin');
    }

    // F22: frozen copy of App\Support\ConcurrentIndex logic (2026-11-10) so
    // this migration never depends on App\ code. CONCURRENTLY is skipped in
    // the testing environment where migrations run inside transactions.
    private function ensureIndex(string $name, string $definition): void
    {
        $this->validateIndexName($name);
        $index = DB::selectOne('SELECT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relname = ?', [$name]);
        if ($index !== null && ! $index->indisvalid) {
            $this->dropIndex($name);
        }
        DB::statement('CREATE INDEX '.$this->concurrently().'IF NOT EXISTS '.$name.' '.$definition);
    }

    private function dropIndex(string $name): void
    {
        $this->validateIndexName($name);
        DB::statement('DROP INDEX '.$this->concurrently().'IF EXISTS '.$name);
    }

    private function validateIndexName(string $name): void
    {
        if (! preg_match('/\A[a-z_][a-z0-9_]*\z/', $name)) {
            throw new \InvalidArgumentException('Invalid index name.');
        }
    }

    private function concurrently(): string
    {
        if (app()->environment('testing')) {
            return '';
        }
        if (DB::transactionLevel() > 0) {
            throw new \LogicException('Concurrent index builds require an independent migration.');
        }

        return 'CONCURRENTLY ';
    }

    private function indexes(): array
    {
        return [
            'products_occasions_gin' => 'ON products USING GIN (occasions)',
            'product_variants_active_color_en' => 'ON product_variants (LOWER(color_name_en), product_id) WHERE is_active AND deleted_at IS NULL',
            'product_variants_active_color_ar' => 'ON product_variants (color_name_ar, product_id) WHERE is_active AND deleted_at IS NULL',
            'products_store_featured_index' => "ON products (is_featured DESC, sales_count DESC, created_at DESC, id DESC) WHERE deleted_at IS NULL AND status = 'published' AND sales_channel IN ('retail', 'both')",
            'products_store_best_selling_index' => "ON products (sales_count DESC, id DESC) WHERE deleted_at IS NULL AND status = 'published' AND sales_channel IN ('retail', 'both')",
            'products_name_en_trgm' => 'ON products USING GIN (lower(name_en) gin_trgm_ops)',
            'products_name_ar_trgm' => 'ON products USING GIN (lower(name_ar) gin_trgm_ops)',
            'product_variants_sku_trgm' => 'ON product_variants USING GIN (lower(sku) gin_trgm_ops) WHERE is_active AND deleted_at IS NULL',
            'products_catalog_retail_price_index' => "ON products (catalog_min_retail_price, id) WHERE deleted_at IS NULL AND status = 'published' AND sales_channel IN ('retail', 'both')",
        ];
    }
};
