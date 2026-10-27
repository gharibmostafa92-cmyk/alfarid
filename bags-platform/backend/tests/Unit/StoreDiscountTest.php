<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\StoreCatalogService;
use PHPUnit\Framework\TestCase;

/**
 * F23: معادلة الخصم الوحيدة — التقريب بعد الضرب في 100، فخصم 30%
 * يبقى 30 (لا يُسقطه تقريب مبكر قبل الضرب).
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
        // F20 family: a custom price above the base never yields a discount.
        $this->assertNull(StoreCatalogService::discountPercentage(99.99, 100.0));
    }
}
