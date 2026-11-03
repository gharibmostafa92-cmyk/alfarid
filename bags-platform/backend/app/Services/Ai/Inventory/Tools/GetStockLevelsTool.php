<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory\Tools;

use App\Models\StockLevel;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Inventory\InventoryArgs;
use App\Services\Ai\Inventory\InventoryPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\BranchAccess;

final class GetStockLevelsTool implements AgentTool
{
    public function __construct(
        private EntityResolver $resolver,
        private BranchAccess $branches,
        private InventoryPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'get_stock_levels';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function risk(): string
    {
        return 'low';
    }

    public function permissions(): array
    {
        return ['inventory.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'رصيد متغير أو منتج حسب الفرع والموقع: الفعلي والمحجوز والتالف والقيد والمتاح.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'variant_id' => ['type' => 'INTEGER'],
                    'sku' => ['type' => 'STRING'],
                    'barcode' => ['type' => 'STRING'],
                    'query' => ['type' => 'STRING'],
                    'product_id' => ['type' => 'INTEGER'],
                    'branch_id' => ['type' => 'INTEGER'],
                    'branch_query' => ['type' => 'STRING'],
                    'location_id' => ['type' => 'INTEGER'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = InventoryArgs::normalize($args);
        $resolved = InventoryArgs::resolveVariant($actor, $this->resolver, $args);
        if ($resolved instanceof ToolOutcome) {
            return $resolved;
        }
        $scope = InventoryArgs::scopeBranch($actor, $this->branches, $args);
        if ($scope instanceof ToolOutcome) {
            return $scope;
        }
        $variant = $resolved['variant'];

        $levels = StockLevel::query()->with(['branch:id,name', 'location:id,name,code'])
            ->where('product_variant_id', $variant->id)
            ->when($scope['branch'] !== null, fn ($q) => $q->where('branch_id', $scope['branch']->id))
            ->when(! empty($args['location_id']), fn ($q) => $q->where('location_id', (int) $args['location_id']))
            ->orderBy('branch_id')->orderBy('location_id')
            ->limit(50)->get();

        $rows = $levels->map(fn (StockLevel $l) => [
            'branch_id' => $l->branch_id,
            'branch_name' => $l->branch?->name,
            'location_id' => $l->location_id,
            'location_name' => $l->location?->name,
            'on_hand' => (float) $l->on_hand,
            'reserved' => (float) $l->reserved,
            'damaged' => (float) $l->damaged,
            'in_transit' => (float) $l->in_transit,
            'available' => $l->available,
        ])->values()->all();

        $table = array_map(fn ($r) => [
            $r['branch_name'] ?? $r['branch_id'],
            (string) $r['on_hand'], (string) $r['reserved'], (string) $r['damaged'], (string) $r['available'],
        ], $rows);

        return $this->presenter->present($actor, [
            'variant' => ['id' => $variant->id, 'sku' => $variant->sku, 'name_ar' => $this->presenter->untrusted($variant->product?->name_ar ?? '', 150)],
            'levels' => $rows,
            'count' => count($rows),
        ], ['type' => 'table', 'columns' => ['الفرع', 'فعلي', 'محجوز', 'تالف', 'متاح'], 'rows' => $table]);
    }
}
