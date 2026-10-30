<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthIndexMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_lookup_migration_recovers_an_invalid_index_and_is_repeatable(): void
    {
        $migration = require database_path('migrations/2026_10_30_000001_auth_lookup_indexes.php');
        $migration->down();
        Customer::factory()->count(2)->create(['name' => 'Repeated name']);
        try {
            DB::statement('CREATE UNIQUE INDEX CONCURRENTLY customers_email_login_idx ON customers (name)');
            $this->fail('Duplicate names should leave an invalid concurrent index.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->errorInfo[0]);
        }
        $migration->up();
        $migration->up();
        $index = DB::selectOne("SELECT i.indisvalid, pg_get_indexdef(i.indexrelid) AS definition FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = 'customers_email_login_idx'");
        $this->assertTrue($index->indisvalid);
        $this->assertStringContainsString('lower', $index->definition);
        $this->assertStringContainsString('deleted_at IS NULL', $index->definition);
        $this->assertNull(DB::selectOne("SELECT 1 FROM pg_class WHERE relname = 'customers_phone_index'"));
    }

    public function test_staff_email_preflight_requires_manual_duplicate_resolution(): void
    {
        $migration = require database_path('migrations/2026_10_30_000001_auth_lookup_indexes.php');
        $migration->down();
        $first = User::factory()->create(['email' => 'duplicate@example.test']);
        DB::table('users')->where('id', $first->id)->update(['email' => 'DUPLICATE@example.test']);
        $second = User::factory()->create(['email' => 'duplicate@example.test']);
        try {
            $migration->up();
            $this->fail('Duplicate staff identities must stop migration.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('duplicate user emails', $exception->getMessage());
        }
        $this->assertSame(2, DB::table('users')->whereRaw('LOWER(email) = ?', ['duplicate@example.test'])->count());
        $second->delete();
        $migration->up();
        $index = DB::selectOne("SELECT i.indisunique, i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = 'users_email_ci_unique'");
        $this->assertTrue($index->indisunique);
        $this->assertTrue($index->indisvalid);
    }
}
