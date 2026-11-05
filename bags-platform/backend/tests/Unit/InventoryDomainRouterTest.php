<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\DomainRouter;
use Tests\TestCase;

class InventoryDomainRouterTest extends TestCase
{
    public function test_transfer_coverage_what_if_routes_to_inventory_without_the_word_stock(): void
    {
        $domains = app(DomainRouter::class)->route('لو نقلت 30 قطعة من P00042-BLK من المعادي للدقي، المعادي هيفضل له تغطية كام يوم؟');
        $this->assertContains('inventory', $domains ?? []);
    }

    public function test_variant_balance_preserves_both_catalog_and_inventory_domains(): void
    {
        $domains = app(DomainRouter::class)->route('رصيد المتغير ده كام؟', [['entity' => 'variant', 'id' => 401]]);
        $this->assertContains('inventory', $domains ?? []);
        $this->assertContains('catalog', $domains ?? []);
    }
}
