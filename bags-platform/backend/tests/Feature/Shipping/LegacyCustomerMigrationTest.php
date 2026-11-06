<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacyCustomerMigrationTest extends TestCase
{
    public function test_customer_structure_migration_can_roll_back_and_reapply_with_legacy_customers(): void
    {
        $this->assertSame('alfarid_testing', DB::connection()->getDatabaseName());
        DB::beginTransaction();

        try {
            DB::statement('CREATE SCHEMA legacy_customer_migration_test');
            DB::statement('SET LOCAL search_path TO legacy_customer_migration_test');

            $users = require database_path('migrations/0001_01_01_000000_create_users_table.php');
            $customers = require database_path('migrations/2026_10_15_014601_create_customers_and_supplier_users_tables.php');
            $upgrade = require database_path('migrations/2026_10_16_000001_complete_customers_structure.php');
            $users->up();
            $customers->up();

            $upgrade->up();
            $this->assertTrue(Schema::hasTable('cities'));
            $upgrade->down();
            $this->assertFalse(Schema::hasTable('cities'));

            $id = DB::table('customers')->insertGetId([
                'name' => 'Legacy customer',
                'email' => 'legacy@example.test',
                'phone' => null,
                'password' => 'unused-test-password',
                'status' => 'blocked',
            ]);
            $upgrade->up();
            $this->assertDatabaseHas('customers', ['id' => $id, 'status' => 'suspended', 'phone' => 'legacy-'.$id]);
            $this->assertTrue(Schema::hasTable('cities'));

            $upgrade->down();
            $this->assertDatabaseHas('customers', ['id' => $id, 'status' => 'blocked']);
            $upgrade->up();
            $this->assertDatabaseHas('customers', ['id' => $id, 'status' => 'suspended', 'phone' => 'legacy-'.$id]);
        } finally {
            DB::rollBack();
        }
    }
}
