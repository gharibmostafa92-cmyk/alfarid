<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory\Tools;

use App\Enums\StockMovementType;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Inventory\InventoryArgs;
use App\Services\Ai\Inventory\InventoryPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\BranchAccess;
use App\Services\CostAccess;

final class ListMovementsTool implements AgentTool
{
    public function __construct(
        private EntityResolver $resolver,
        private BranchAccess $branches,
        private InventoryPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'list_movements';
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
            'description' => 'دفتر حركات المخزون بفلاتر (نوع، فرع، متغير، مستخدم، فترة) مع cursor.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'type' => ['type' => 'STRING', 'enum' => array_column(StockMovementType::cases(), 'value')],
                    'variant_id' => ['type' => 'INTEGER'],
                    'sku' => ['type' => 'STRING'],
                    'query' => ['type' => 'STRING'],
                    'branch_id' => ['type' => 'INTEGER'],
                    'branch_query' => ['type' => 'STRING'],
                    'user_id' => ['type' => 'INTEGER'],
                    'reference_type' => ['type' => 'STRING'],
                    'reference_id' => ['type' => 'INTEGER'],
                    'reference_number' => ['type' => 'STRING'],
                    'from' => ['type' => 'STRING'],
                    'to' => ['type' => 'STRING'],
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

        $variantId = null;
        if (! empty($args['variant_id']) || ! empty($args['sku']) || ! empty($args['query'])) {
            $resolved = InventoryArgs::resolveVariant($actor, $this->resolver, $args);
            if ($resolved instanceof ToolOutcome) {
                return $resolved;
            }
            $variantId = $resolved['variant']->id;
        }

        $limit = min(50, max(1, (int) ($args['limit'] ?? 20)));
        $movements = StockMovement::query()->with(['variant:id,sku', 'branch:id,name'])
            ->when($scope['branch'] !== null, fn ($q) => $q->where('branch_id', $scope['branch']->id))
            ->when($variantId !== null, fn ($q) => $q->where('product_variant_id', $variantId))
            ->when($args['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($args['user_id'] ?? null, fn ($q, $v) => $q->where('created_by', (int) $v))
            ->when($args['reference_type'] ?? null, fn ($q, $v) => $q->where('reference_type', $v))
            ->when($args['reference_id'] ?? null, fn ($q, $v) => $q->where('reference_id', (int) $v))
            ->when($args['reference_number'] ?? null, fn ($q, $v) => $q->where('reference_number', $v))
            ->when($args['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($args['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v))
            ->when($args['cursor'] ?? null, fn ($q, $v) => $q->where('id', '<', (int) $v))
            ->orderByDesc('id')->limit($limit + 1)->get();

        $hasMore = $movements->count() > $limit;
        $items = $movements->take($limit)->map(fn (StockMovement $m) => [
            'id' => $m->id,
            'reference_number' => $m->reference_number,
            'variant_id' => $m->product_variant_id,
            'sku' => $m->variant?->sku,
            'branch_id' => $m->branch_id,
            'branch_name' => $m->branch?->name,
            'type' => $m->type->value,
            'affected_bucket' => $m->affected_bucket->value,
            'quantity' => (float) $m->quantity,
            'balance_before' => (float) $m->balance_before,
            'balance_after' => (float) $m->balance_after,
            'unit_cost' => $m->unit_cost !== null ? (float) $m->unit_cost : null,
            'created_by' => $m->created_by,
            'notes' => $m->notes !== null ? $this->presenter->untrusted($m->notes, 200) : null,
            'created_at' => $m->created_at?->toIso8601String(),
        ])->values()->all();

        $showCost = CostAccess::canView($actor);
        $table = array_map(fn ($i) => array_merge([
            (string) $i['id'], $i['reference_number'] ?? '—', $i['sku'] ?? '—',
            $i['type'], (string) $i['quantity'], (string) $i['balance_after'],
        ], $showCost ? [$i['unit_cost'] !== null ? (string) $i['unit_cost'] : '—'] : []), $items);
        $columns = ['#', 'المرجع', 'المتغير', 'النوع', 'الكمية', 'الرصيد بعد'];
        if ($showCost) {
            $columns[] = 'تكلفة الوحدة';
        }

        return $this->presenter->present($actor, [
            'movements' => $items,
            'next_cursor' => $hasMore ? end($items)['id'] ?? null : null,
            'has_more' => $hasMore,
        ], ['type' => 'table', 'columns' => $columns, 'rows' => $table]);
    }
}
