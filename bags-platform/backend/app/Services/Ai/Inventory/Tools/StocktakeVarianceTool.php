<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory\Tools;

use App\Enums\StockMovementType;
use App\Enums\StocktakeStatus;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Inventory\InventoryArgs;
use App\Services\Ai\Inventory\InventoryPresenter;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\BranchAccess;
use App\Services\StocktakeService;

final class StocktakeVarianceTool extends StocktakeToolBase implements AgentTool
{
    public function __construct(
        EntityResolver $resolver,
        BranchAccess $branches,
        StocktakeService $stocktakes,
        ProposalBuilder $proposals,
        private InventoryPresenter $presenter,
    ) {
        parent::__construct($resolver, $branches, $stocktakes, $proposals);
    }

    public function name(): string
    {
        return 'stocktake_variance';
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
            'description' => 'فروقات الجرد قبل الاعتماد: أكبر 10 فروق بالقيمة، والفروق المشبوهة، والأصناف المتعدّة صفر مع بيع أمس.',
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
        $resolved = $this->resolveStocktake($actor, $args);
        if ($resolved instanceof ToolOutcome) {
            return $resolved;
        }
        $stocktake = $this->stocktakes->find($resolved['stocktake']->id);
        if ($stocktake->status !== StocktakeStatus::Approved && ! $actor->can('stocktakes.approve')) {
            return ToolOutcome::denied('فروقات الجرد المفتوح متاحة لصاحب صلاحية الاعتماد فقط.');
        }
        $stocktake->loadMissing(['items.variant']);

        $rows = [];
        foreach ($stocktake->items as $item) {
            $diff = $item->difference !== null ? (float) $item->difference : ($item->counted_qty !== null ? (float) $item->counted_qty - (float) $item->system_qty : null);
            if ($diff === null || $diff == 0) {
                continue;
            }
            $cost = $item->variant?->cost_price !== null ? (float) $item->variant->cost_price : 0.0;
            $rows[] = [
                'variant_id' => $item->product_variant_id,
                'sku' => $item->variant?->sku,
                'system_qty' => $item->system_qty !== null ? (float) $item->system_qty : null,
                'counted_qty' => $item->counted_qty !== null ? (float) $item->counted_qty : null,
                'difference' => $diff,
                'value_impact' => round(abs($diff) * $cost, 2),
            ];
        }
        usort($rows, fn ($a, $b) => $b['value_impact'] <=> $a['value_impact']);
        $top = array_slice($rows, 0, 10);

        // Flag recurring deficits across recent approved stocktakes in the same location.
        $prior = Stocktake::query()->where('branch_id', $stocktake->branch_id)
            ->where('id', '!=', $stocktake->id)
            ->where('location_id', $stocktake->location_id)
            ->where('status', StocktakeStatus::Approved)
            ->orderByDesc('id')->limit(2)->with('items')->get();
        $shortCounts = [];
        foreach ($prior as $s) {
            foreach ($s->items as $item) {
                $d = $item->difference !== null ? (float) $item->difference : 0.0;
                if ($d < 0) {
                    $shortCounts[$item->product_variant_id] = ($shortCounts[$item->product_variant_id] ?? 0) + 1;
                }
            }
        }
        $suspicious = [];
        foreach ($rows as $r) {
            if ($r['difference'] < 0 && ($shortCounts[$r['variant_id']] ?? 0) >= 2) {
                $suspicious[] = ['variant_id' => $r['variant_id'], 'sku' => $r['sku'], 'prior_shortages' => $shortCounts[$r['variant_id']]];
            }
        }

        // Flag probable miscounts: items counted as zero despite having recorded sales yesterday.
        $zeroSuspicious = [];
        $zeroIds = array_values(array_filter(array_map(fn ($r) => $r['counted_qty'] === 0.0 ? $r['variant_id'] : null, $rows)));
        if ($zeroIds !== []) {
            $soldYesterday = StockMovement::query()->where('branch_id', $stocktake->branch_id)
                ->where('location_id', $stocktake->location_id)
                ->whereIn('product_variant_id', $zeroIds)
                ->where('type', StockMovementType::Sale)
                ->whereDate('created_at', today()->subDay())
                ->distinct()->pluck('product_variant_id')->all();
            foreach ($rows as $r) {
                if ($r['counted_qty'] === 0.0 && in_array($r['variant_id'], $soldYesterday, true)) {
                    $zeroSuspicious[] = ['variant_id' => $r['variant_id'], 'sku' => $r['sku']];
                }
            }
        }

        $table = array_map(fn ($r) => [
            $r['sku'] ?? (string) $r['variant_id'],
            $r['system_qty'] !== null ? (string) $r['system_qty'] : '—',
            $r['counted_qty'] !== null ? (string) $r['counted_qty'] : '—',
            (string) $r['difference'],
        ], $top);

        return $this->presenter->present($actor, [
            'stocktake' => ['id' => $stocktake->id, 'stocktake_number' => $stocktake->stocktake_number],
            'top_by_value' => $top,
            'suspicious_repeat_shortage' => $suspicious,
            'zero_with_yesterday_sale' => $zeroSuspicious,
            'deep_link' => '#/stocktakes/'.$stocktake->id,
        ], ['type' => 'table', 'columns' => ['الصنف', 'النظام', 'العد', 'الفرق'], 'rows' => $table]);
    }
}
