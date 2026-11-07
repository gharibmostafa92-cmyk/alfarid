<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseIndexTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_lookup_indexes_exist_for_customer_supplier_branch_and_location_queries(): void
    {
        $this->assertTrue(Schema::hasIndex('customers', ['type', 'status']));
        $this->assertTrue(Schema::hasIndex('customers', ['phone']));
        $this->assertTrue(Schema::hasIndex('supplier_users', ['supplier_id', 'is_active']));
        $this->assertTrue(Schema::hasIndex('branches', ['type', 'is_active']));
        $this->assertTrue(Schema::hasIndex('locations', ['branch_id', 'is_active', 'type']));
    }

    public function test_catalog_foreign_key_and_sort_indexes_exist(): void
    {
        // Products.category_id/brand_id were Seq Scans on Postgres; the
        // variants relation orders by (product_id, sort_order).
        $this->assertTrue(Schema::hasIndex('products', ['category_id']));
        $this->assertTrue(Schema::hasIndex('products', ['brand_id']));
        $this->assertTrue(
            (bool) DB::selectOne(
                'SELECT 1 FROM pg_indexes WHERE indexname = ?', ['product_variants_product_sort_index']
            ),
            'Missing index product_variants_product_sort_index'
        );
    }

    public function test_admin_search_trigram_indexes_exist(): void
    {
        // Name_ar/name_en ILIKE '%…%' in the backoffice/AI search is
        // served by raw-column GIN trigram indexes; the lower() variants
        // serve LOWER(col) LIKE storefront queries.
        foreach ([
            'products_name_ar_ilike_trgm',
            'products_name_en_ilike_trgm',
            'products_name_en_trgm',
            'products_name_ar_trgm',
            'product_variants_sku_trgm',
        ] as $index) {
            $this->assertTrue(
                (bool) DB::selectOne(
                    'SELECT 1 FROM pg_indexes WHERE indexname = ?', [$index]
                ),
                "Missing index {$index}"
            );
        }
    }

    public function test_product_create_key_partial_unique_index_exists(): void
    {
        // Idempotent product creation relies on this partial index.
        $row = DB::selectOne(
            "SELECT indexdef FROM pg_indexes WHERE indexname = 'products_creator_create_key_unique'"
        );
        $this->assertNotNull($row, 'Missing index products_creator_create_key_unique');
        $this->assertStringContainsString('create_key IS NOT NULL', (string) $row->indexdef);
    }

    public function test_case_insensitive_uniqueness_and_media_idempotency_indexes_exist(): void
    {
        // Functional unique indexes and partial unique client_upload_id index.
        foreach ([
            ['products_slug_lower_unique'],
            ['categories_slug_lower_unique'],
            ['brands_slug_lower_unique'],
            ['product_variants_sku_lower_unique'],
            ['product_variants_barcode_lower_unique'],
            ['media_client_upload_id_unique'],
        ] as [$index]) {
            $this->assertTrue(
                (bool) DB::selectOne(
                    'SELECT 1 FROM pg_indexes WHERE indexname = ?', [$index]
                ),
                "Missing index {$index}"
            );
        }
    }
}
