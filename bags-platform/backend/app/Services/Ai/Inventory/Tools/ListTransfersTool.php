<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory\Tools;

use App\Enums\Inventory\TransferStatus;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Inventory\InventoryArgs;
use App\Services\Ai\Inventory\InventoryPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Inventory\BranchAccess;

final class ListTransfersTool implements AgentTool
{
    public function __construct(
        private BranchAccess $branches,
        private InventoryPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'list_transfers';
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
        return ['transfers.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'قائمة التحويلات بين الفروع بالحالة مع cursor.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'status' => ['type' => 'STRING', 'enum' => array_column(TransferStatus::cases(), 'value')],
                    'from_branch_id' => ['type' => 'INTEGER'],
                    'to_branch_id' => ['type' => 'INTEGER'],
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

        $limit = min(50, max(1, (int) ($args['limit'] ?? 20)));
        $rows = StockTransfer::query()->with(['fromBranch:id,name', 'toBranch:id,name'])->withCount('items')
            ->when($restricted !== null, fn ($q) => $q->where(fn ($w) => $w->where('from_branch_id', $restricted)->orWhere('to_branch_id', $restricted)))
            ->when($args['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($args['from_branch_id'] ?? null, fn ($q, $v) => $q->where('from_branch_id', (int) $v))
            ->when($args['to_branch_id'] ?? null, fn ($q, $v) => $q->where('to_branch_id', (int) $v))
            ->when($args['branch_id'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('from_branch_id', (int) $v)->orWhere('to_branch_id', (int) $v)))
            ->when($args['cursor'] ?? null, fn ($q, $v) => $q->where('id', '<', (int) $v))
            ->orderByDesc('id')->limit($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->map(fn (StockTransfer $t) => [
            'id' => $t->id,
            'transfer_number' => $t->transfer_number,
            'from_branch' => $t->fromBranch?->name,
            'to_branch' => $t->toBranch?->name,
            'status' => $t->status->value,
            'items_count' => (int) ($t->items_count ?? 0),
            'created_at' => $t->created_at?->toIso8601String(),
            'deep_link' => '#/transfers/'.$t->id,
        ])->values()->all();

        $table = array_map(fn ($i) => [
            $i['transfer_number'], ($i['from_branch'] ?? '—').' ← '.($i['to_branch'] ?? '—'), $i['status'],
        ], $items);

        return $this->presenter->present($actor, [
            'transfers' => $items,
            'next_cursor' => $hasMore ? end($items)['id'] ?? null : null,
            'has_more' => $hasMore,
        ], ['type' => 'table', 'columns' => ['الرقم', 'المسار', 'الحالة'], 'rows' => $table]);
    }
}
