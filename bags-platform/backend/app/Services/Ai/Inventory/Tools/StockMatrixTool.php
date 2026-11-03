<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory\Tools;

use App\Models\Product;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Inventory\InventoryArgs;
use App\Services\Ai\Inventory\InventoryPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\BranchAccess;

final class StockMatrixTool implements AgentTool
{
    public function __construct(
        private EntityResolver $resolver,
        private BranchAccess $branches,
        private InventoryPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'stock_matrix';
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
            'description' => 'مصفوفة متغيرات × فروع لمنتج كامل: المتاح والفعلي لكل خلية.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['product_id'],
                'properties' => (object) [
                    'product_id' => ['type' => 'INTEGER'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = InventoryArgs::normalize($args);
        $product = Product::with(['variants' => fn ($q) => $q->orderBy('id')])->find((int) ($args['product_id'] ?? 0));
        if (! $product) {
            return ToolOutcome::denied('المنتج مش موجود.', auditable: false);
        }
        try {
            $restricted = $this->branches->restrictedBranch($actor);
        } catch (\Throwable) {
            return ToolOutcome::denied('النطاق غير مسموح.', auditable: false);
        }

        $variantIds = $product->variants->take(50)->modelKeys();
        $levels = StockLevel::query()->with('branch:id,name')
            ->whereIn('product_variant_id', $variantIds)
            ->when($restricted !== null, fn ($q) => $q->where('branch_id', $restricted))
            ->orderBy('branch_id')->orderBy('product_variant_id')
            ->limit(500)->get();

        $branchNames = [];
        $cells = [];
        foreach ($levels as $l) {
            $branchNames[$l->branch_id] = $l->branch?->name ?? (string) $l->branch_id;
            $cells[$l->product_variant_id][$l->branch_id] = [
                'on_hand' => (float) $l->on_hand,
                'available' => $l->available,
            ];
        }

        $rows = [];
        foreach ($product->variants->take(50) as $v) {
            $row = ['variant_id' => $v->id, 'sku' => $v->sku, 'cells' => []];
            foreach (array_keys($branchNames) as $bid) {
                $row['cells'][$bid] = $cells[$v->id][$bid] ?? ['on_hand' => 0.0, 'available' => 0.0];
            }
            $rows[] = $row;
        }

        $header = array_values($branchNames);
        $table = array_map(fn ($r) => array_merge(
            [$r['sku']],
            array_map(fn ($bid) => (string) ($r['cells'][$bid]['available'] ?? 0), array_keys($branchNames))
        ), $rows);

        return $this->presenter->present($actor, [
            'product' => ['id' => $product->id, 'name_ar' => $this->presenter->untrusted($product->name_ar, 150)],
            'branches' => $branchNames,
            'rows' => $rows,
        ], ['type' => 'stock_matrix', 'columns' => array_merge(['المتغير'], $header), 'rows' => $table, 'deep_link' => '#/products/'.$product->id]);
    }
}
