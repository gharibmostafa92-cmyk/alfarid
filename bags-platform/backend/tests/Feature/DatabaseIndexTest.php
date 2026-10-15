<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
}
