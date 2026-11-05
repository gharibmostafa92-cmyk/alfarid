<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\Inventory\InventoryArgs;
use PHPUnit\Framework\TestCase;

class InventoryArgsTest extends TestCase
{
    public function test_nested_arabic_quantities_and_transfer_keys_are_normalized_without_changing_notes(): void
    {
        $args = InventoryArgs::normalize(['from_branch_id' => '٧', 'to_branch_id' => '۸',
            'items' => [['product_variant_id' => '۴۲', 'quantity' => '٢٫٥', 'unit_cost' => '١٧٠٫٢٥']],
            'notes' => 'دفعة ١٧٠']);
        $this->assertSame(7, $args['from_branch_id']);
        $this->assertSame(8, $args['to_branch_id']);
        $this->assertSame(['product_variant_id' => 42, 'quantity' => '2.5', 'unit_cost' => '170.25'], $args['items'][0]);
        $this->assertSame('دفعة ١٧٠', $args['notes']);
    }
}
