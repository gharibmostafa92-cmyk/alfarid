<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Catalog\StoreCatalogService;
use PHPUnit\Framework\TestCase;

/**
 * Storefront discount percentage rounding: multiply by 100 before rounding to preserve exact integer percentages.
 */
class StoreDiscountTest extends TestCase
{
    public function test_small_discounts_survive_rounding(): void
    {
        $this->assertSame(30, StoreCatalogService::discountPercentage(70.0, 100.0));
        $this->assertSame(1, StoreCatalogService::discountPercentage(99.0, 100.0));
    }

    public function test_no_discount_without_positive_gap(): void
    {
        $this->assertNull(StoreCatalogService::discountPercentage(100.0, 100.0));
        $this->assertNull(StoreCatalogService::discountPercentage(120.0, 100.0));
        // Custom price above base price never yields a discount.
        $this->assertNull(StoreCatalogService::discountPercentage(99.99, 100.0));
    }
}
