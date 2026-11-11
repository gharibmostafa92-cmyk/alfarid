<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventoryIdempotencyPruneIndexTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_prune_loop_created_at_index_exists(): void
    {
        // The prune loop (WHERE created_at < ? ORDER BY created_at LIMIT 1000) relies on
        // the index created by 2026_10_15_214043. This guards against any future
        // migration or rollback helper dropping it: without the index every
        // prune batch full-scans the table.
        $this->assertTrue(
            collect(Schema::getIndexes('inventory_idempotency_keys'))
                ->contains(fn (array $index): bool => ($index['name'] ?? '') === 'inventory_idempotency_keys_created_at_index'),
            'Prune loop needs inventory_idempotency_keys_created_at_index.'
        );
    }
}
