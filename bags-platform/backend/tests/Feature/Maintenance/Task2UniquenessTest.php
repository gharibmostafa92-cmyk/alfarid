<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Rules\UniqueCatalogIdentifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** task2 M6: application uniqueness checks must agree with PostgreSQL and race safely. */
class Task2UniquenessTest extends TestCase
{
    public function test_catalog_identifier_rule_normalizes_unicode_case(): void
    {
        DB::statement('CREATE TEMP TABLE t2_catalog (id serial primary key, sku text)');
        DB::table('t2_catalog')->insert(['sku' => 'sku-é']);

        $failed = false;
        (new UniqueCatalogIdentifier('t2_catalog', 'sku'))
            ->validate('sku', 'SKU-É', function () use (&$failed): void {
                $failed = true;
            });

        $this->assertTrue($failed, 'A unicode-cased duplicate was not detected.');
    }

    public function test_duplicate_unique_violation_is_reported_as_unprocessable(): void
    {
        DB::statement('CREATE TEMP TABLE t2_unique_guard (code text UNIQUE)');
        Route::get('/api/v1/t2-unique-guard', function () {
            DB::table('t2_unique_guard')->insert(['code' => 'dup']);
            DB::table('t2_unique_guard')->insert(['code' => 'dup']);

            return 'ok';
        });

        $this->getJson('/api/v1/t2-unique-guard')->assertStatus(422);
    }

    public function test_data_exception_sqlstate_is_reported_as_unprocessable(): void
    {
        Route::get('/api/v1/t2-data-exception', function () {
            DB::selectOne('select 1 / 0');

            return 'ok';
        });

        $this->getJson('/api/v1/t2-data-exception')->assertStatus(422);
    }
}
