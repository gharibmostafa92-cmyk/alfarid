<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory\Tools;

use App\Enums\StocktakeStatus;
use App\Models\Stocktake;
use App\Models\StocktakeItem;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Inventory\InventoryArgs;
use App\Services\Ai\Inventory\InventoryPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\BranchAccess;
use App\Services\StocktakeService;

final class GetStocktakeTool implements AgentTool
{
    public function __construct(
        private EntityResolver $resolver,
        private BranchAccess $branches,
        private StocktakeService $stocktakes,
        private InventoryPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'get_stocktake';
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
            'description' => 'تفاصيل جلسة جرد: تقدم العد والبنود.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'stocktake_id' => ['type' => 'INTEGER'],
                    'stocktake_number' => ['type' => 'STRING'],
                    'query' => ['type' => 'STRING'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = InventoryArgs::normalize($args);
        $stocktake = null;
        if (! empty($args['stocktake_id'])) {
            $stocktake = Stocktake::find((int) $args['stocktake_id']);
        } elseif (! empty($args['stocktake_number'])) {
            $stocktake = Stocktake::whereRaw('LOWER(stocktake_number) = LOWER(?)', [trim((string) $args['stocktake_number'])])->first();
        } else {
            $options = $this->resolver->stocktakes($actor, (string) ($args['query'] ?? ''));
            if (count($options) !== 1) {
                return ToolOutcome::denied('حدد الجرد برقمه. استخدم find_entities ثم ask_user_to_choose.', auditable: false);
            }
            $stocktake = Stocktake::find($options[0]['id']);
        }
        if (! $stocktake) {
            return ToolOutcome::denied('الجرد مش موجود.', auditable: false);
        }
        try {
            $restricted = $this->branches->restrictedBranch($actor);
        } catch (\Throwable) {
            return ToolOutcome::denied('النطاق غير مسموح.', auditable: false);
        }
        if ($restricted !== null && (int) $stocktake->branch_id !== $restricted) {
            return ToolOutcome::denied('الجرد خارج نطاق فرعك.', auditable: false);
        }
        $stocktake = $this->stocktakes->find($stocktake->id);
        $stocktake->load(['items.variant:id,sku', 'branch:id,name']);

        $total = $stocktake->items->count();
        $counted = $stocktake->items->whereNotNull('counted_qty')->count();
        $items = $stocktake->items->take(100)->map(fn (StocktakeItem $i) => [
            'variant_id' => $i->product_variant_id,
            'sku' => $i->variant?->sku,
            'system_qty' => $i->system_qty !== null ? (float) $i->system_qty : null,
            'counted_qty' => $i->counted_qty !== null ? (float) $i->counted_qty : null,
            'difference' => $i->difference !== null ? (float) $i->difference : null,
        ])->values()->all();

        $reveal = $stocktake->status === StocktakeStatus::Approved || $actor->can('stocktakes.approve');
        if (! $reveal) {
            $items = array_map(function ($item) {
                unset($item['system_qty'], $item['difference']);

                return $item;
            }, $items);
        }

        return $this->presenter->present($actor, [
            'stocktake' => [
                'id' => $stocktake->id,
                'stocktake_number' => $stocktake->stocktake_number,
                'branch_name' => $stocktake->branch?->name,
                'status' => $stocktake->status->value,
            ],
            'progress' => ['total' => $total, 'counted' => $counted],
            'items' => $items,
            'items_truncated' => $total > 100,
            'deep_link' => '#/stocktakes/'.$stocktake->id,
        ], ['type' => 'table', 'columns' => $reveal ? ['الصنف', 'النظام', 'العد', 'الفرق'] : ['الصنف', 'العد'], 'rows' => array_map(fn ($i) => $reveal ? [
            $i['sku'] ?? (string) $i['variant_id'],
            $i['system_qty'] !== null ? (string) $i['system_qty'] : '—',
            $i['counted_qty'] !== null ? (string) $i['counted_qty'] : '—',
            $i['difference'] !== null ? (string) $i['difference'] : '—',
        ] : [$i['sku'] ?? (string) $i['variant_id'], $i['counted_qty'] !== null ? (string) $i['counted_qty'] : '—'], $items)]);
    }
}
