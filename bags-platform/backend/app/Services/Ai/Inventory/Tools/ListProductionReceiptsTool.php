<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory\Tools;

use App\Models\ProductionReceipt;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Inventory\InventoryArgs;
use App\Services\Ai\Inventory\InventoryPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\BranchAccess;

final class ListProductionReceiptsTool implements AgentTool
{
    public function __construct(
        private BranchAccess $branches,
        private InventoryPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'list_production_receipts';
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
            'description' => 'استلامات الإنتاج ودفعاتها (التكلفة مشروطة بصلاحية العرض).',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'branch_id' => ['type' => 'INTEGER'],
                    'branch_query' => ['type' => 'STRING'],
                    'cursor' => ['type' => 'INTEGER'],
                    'limit' => ['type' => 'INTEGER'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = InventoryArgs::normalize($args);
        $scope = InventoryArgs::scopeBranch($actor, $this->branches, $args);
        if ($scope instanceof ToolOutcome) {
            return $scope;
        }

        $limit = min(50, max(1, (int) ($args['limit'] ?? 20)));
        $rows = ProductionReceipt::query()->with('branch:id,name')
            ->when($scope['branch'] !== null, fn ($q) => $q->where('branch_id', $scope['branch']->id))
            ->when($args['cursor'] ?? null, fn ($q, $v) => $q->where('id', '<', (int) $v))
            ->orderByDesc('id')->limit($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->map(fn (ProductionReceipt $r) => [
            'id' => $r->id,
            'batch_number' => $r->batch_number,
            'branch_name' => $r->branch?->name,
            'items' => $r->items ?? [],
            'notes' => $r->notes !== null ? $this->presenter->untrusted($r->notes, 200) : null,
            'created_at' => $r->created_at?->toIso8601String(),
            'deep_link' => '#/production/'.$r->id,
        ])->values()->all();

        $table = array_map(fn ($i) => [
            $i['batch_number'], $i['branch_name'] ?? '—', (string) count($i['items'] ?? []),
        ], $items);

        return $this->presenter->present($actor, [
            'receipts' => $items,
            'next_cursor' => $hasMore ? end($items)['id'] ?? null : null,
            'has_more' => $hasMore,
        ], ['type' => 'table', 'columns' => ['الدفعة', 'الفرع', 'البنود'], 'rows' => $table]);
    }
}
