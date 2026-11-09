<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory\Tools;

use App\Enums\Inventory\StocktakeStatus;
use App\Models\Stocktake;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Inventory\InventoryArgs;
use App\Services\Ai\Inventory\InventoryPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Inventory\BranchAccess;

final class ListStocktakesTool implements AgentTool
{
    public function __construct(
        private BranchAccess $branches,
        private InventoryPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'list_stocktakes';
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
        return ['stocktakes.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'جلسات الجرد وتقدم العد بالحالة مع cursor.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'status' => ['type' => 'STRING', 'enum' => array_column(StocktakeStatus::cases(), 'value')],
                    'branch_id' => ['type' => 'INTEGER'],
                    'cursor' => ['type' => 'INTEGER'],
                    'limit' => ['type' => 'INTEGER'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = InventoryArgs::normalize($args);
        try {
            $restricted = $this->branches->restrictedBranch($actor);
        } catch (\Throwable) {
            return ToolOutcome::denied('النطاق غير مسموح.', auditable: false);
        }
        $branchId = $restricted ?? (isset($args['branch_id']) ? (int) $args['branch_id'] : null);

        $limit = min(50, max(1, (int) ($args['limit'] ?? 20)));
        $rows = Stocktake::query()->with('branch:id,name')->withCount('items')
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when($args['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($args['cursor'] ?? null, fn ($q, $v) => $q->where('id', '<', (int) $v))
            ->orderByDesc('id')->limit($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->map(fn (Stocktake $s) => [
            'id' => $s->id,
            'stocktake_number' => $s->stocktake_number,
            'branch_name' => $s->branch?->name,
            'status' => $s->status->value,
            'items_count' => (int) ($s->items_count ?? 0),
            'created_at' => $s->created_at?->toIso8601String(),
            'deep_link' => '#/stocktakes/'.$s->id,
        ])->values()->all();

        $table = array_map(fn ($i) => [
            $i['stocktake_number'], $i['branch_name'] ?? '—', $i['status'], (string) $i['items_count'],
        ], $items);

        return $this->presenter->present($actor, [
            'stocktakes' => $items,
            'next_cursor' => $hasMore ? end($items)['id'] ?? null : null,
            'has_more' => $hasMore,
        ], ['type' => 'table', 'columns' => ['الرقم', 'الفرع', 'الحالة', 'البنود'], 'rows' => $table]);
    }
}
