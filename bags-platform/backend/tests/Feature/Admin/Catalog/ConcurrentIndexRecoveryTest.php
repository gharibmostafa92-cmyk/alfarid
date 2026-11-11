<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Catalog;

use App\Models\Product;
use App\Support\ConcurrentIndex;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ConcurrentIndexRecoveryTest extends TestCase
{
    use DatabaseMigrations;

    public function test_the_columns_migration_can_resume_after_a_partial_older_run(): void
    {
        $migration = require database_path('migrations/2026_10_25_100000_add_storefront_fields_to_products_table.php');
        $migration->up();
        $migration->up();
        $this->assertTrue(Schema::hasColumns('products', ['occasions', 'badge_en', 'badge_ar']));
    }

    public function test_a_failed_concurrent_build_is_recreated_instead_of_silently_skipped(): void
    {
        Product::factory()->count(2)->create(['name_en' => 'Duplicate name']);
        try {
            DB::statement('CREATE UNIQUE INDEX CONCURRENTLY audit_broken_index ON products (name_en)');
            $this->fail('Duplicate unique build unexpectedly succeeded.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->errorInfo[0]);
        }
        try {
            $this->assertFalse((bool) DB::selectOne("SELECT indisvalid FROM pg_index WHERE indexrelid = 'audit_broken_index'::regclass")->indisvalid);
            ConcurrentIndex::ensure('audit_broken_index', 'ON products (name_en)');
            $this->assertTrue((bool) DB::selectOne("SELECT indisvalid FROM pg_index WHERE indexrelid = 'audit_broken_index'::regclass")->indisvalid);
        } finally {
            ConcurrentIndex::drop('audit_broken_index');
        }
    }

    public function test_a_valid_index_with_the_same_name_and_different_definition_is_rejected(): void
    {
        DB::statement('CREATE INDEX audit_definition_index ON products (name_en) WHERE deleted_at IS NULL');
        try {
            $this->assertFalse(ConcurrentIndex::ensure('audit_definition_index', 'ON products (name_en) WHERE deleted_at IS NULL'));
            $this->expectExceptionMessage('Existing index audit_definition_index has a different definition');
            ConcurrentIndex::ensure('audit_definition_index', 'ON products (name_en)');
        } finally {
            ConcurrentIndex::drop('audit_definition_index');
        }
    }
}
