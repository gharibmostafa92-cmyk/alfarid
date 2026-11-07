<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\Catalog\CatalogArgs;
use PHPUnit\Framework\TestCase;

class CatalogArgsTest extends TestCase
{
    public function test_digits_are_normalized_in_nested_lists_without_changing_free_text_or_types(): void
    {
        $this->assertSame([
            'variant_matrix' => ['sizes' => ['42', '43'], 'capacities' => ['20 لتر']],
            'scope' => ['product_ids' => ['12', '34']],
            'name_ar' => 'شنطة ٤٥٠',
            'retail_price' => 850.5,
            'category_id' => 12,
            'sku' => 'BAG-42',
            'slug' => 'bag-42',
            'is_active' => false,
            'optional' => null,
        ], CatalogArgs::normalizeDigitsInArray([
            'variant_matrix' => ['sizes' => ['٤٢', '۴۳'], 'capacities' => ['٢٠ لتر']],
            'scope' => ['product_ids' => ['١٢', '۳۴']],
            'name_ar' => 'شنطة ٤٥٠',
            'retail_price' => '٨٥٠.٥',
            'category_id' => '١٢',
            'sku' => ' bag-٤٢ ',
            'slug' => ' BAG-٤٢ ',
            'is_active' => false,
            'optional' => null,
        ]));

        $this->assertSame(
            CatalogArgs::normalizeDigitsInArray(['retail_price' => '٨٥٠.٥']),
            CatalogArgs::normalize(['retail_price' => '٨٥٠.٥'])
        );
    }
}
