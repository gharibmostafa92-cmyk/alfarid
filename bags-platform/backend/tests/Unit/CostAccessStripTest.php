<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ProductVariant;
use App\Services\Shared\CostAccess;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class CostAccessStripTest extends TestCase
{
    public function test_default_keys_strip_all_cost_aliases_recursively(): void
    {
        $clean = CostAccess::strip([
            'id' => 7,
            'cost_price' => 9.5,
            'cost' => 9.5,
            'unit_cost' => 9.5,
            'total_cost' => 19.0,
            'avg_cost' => 9.5,
            'nested' => ['ok' => 1, 'unit_cost' => 3.0, 'total_cost' => 6.0],
        ]);

        $this->assertSame(['id' => 7, 'nested' => ['ok' => 1]], $clean);
    }

    public function test_explicit_keys_still_limited_to_given_list(): void
    {
        $clean = CostAccess::strip(['unit_cost' => 5.0, 'cost_price' => 9.0], ['cost_price']);

        $this->assertSame(['unit_cost' => 5.0], $clean);
    }

    public function test_derived_cost_fields_are_stripped_by_default(): void
    {
        // M8: sale price minus margin reveals cost, so derived fields must go too.
        $clean = CostAccess::strip([
            'id' => 7,
            'retail_price' => 15.0,
            'margin' => 5.5,
            'profit' => 5.5,
            'inventory_value' => 95.0,
            'purchase_price' => 9.5,
            'nested' => ['retail_price' => 20.0, 'margin' => 8.0],
        ]);

        $this->assertSame(['id' => 7, 'retail_price' => 15.0, 'nested' => ['retail_price' => 20.0]], $clean);
    }

    public function test_nested_json_resource_is_resolved_before_stripping(): void
    {
        // M8: a pass-through Resource would otherwise leak cost_price untouched.
        $resource = new class(['sku' => 'R-1', 'cost_price' => 9.5, 'retail_price' => 15.0]) extends \Illuminate\Http\Resources\Json\JsonResource
        {
            /** @param \Illuminate\Http\Request $request */
            public function toArray($request): array
            {
                return is_array($this->resource) ? $this->resource : [];
            }
        };

        $clean = CostAccess::strip(['id' => 1, 'variant' => $resource]);

        $this->assertIsArray($clean['variant']);
        $this->assertSame('R-1', $clean['variant']['sku']);
        $this->assertArrayNotHasKey('cost_price', $clean['variant']);
    }

    public function test_nested_models_and_collections_are_normalized_before_stripping(): void
    {
        $variant = (new ProductVariant)->forceFill(['sku' => 'X-1', 'cost_price' => 9.5, 'retail_price' => 15.0]);

        $clean = CostAccess::strip([
            'id' => 1,
            'variant' => $variant,
            'lines' => new Collection([['sku' => 'Y-2', 'unit_cost' => 4.0]]),
        ]);

        $this->assertSame(1, $clean['id']);
        $this->assertIsArray($clean['variant']);
        $this->assertSame('X-1', $clean['variant']['sku']);
        $this->assertArrayNotHasKey('cost_price', $clean['variant']);
        $this->assertSame([['sku' => 'Y-2']], array_values($clean['lines']));
    }
}
