<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory\Tools;

use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Inventory\InventoryArgs;
use App\Services\Ai\Inventory\InventoryPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Inventory\BranchAccess;
use Illuminate\Support\Facades\DB;

final class LowStockListTool implements AgentTool
{
    public function __construct(
        private BranchAccess $branches,
        private InventoryPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'low_stock_list';
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
            'description' => 'الأصناف تحت حد إعادة الطلب مرتبة بأيام التغطية (المتاح ÷ متوسط البيع اليومي آخر 30 يوم).',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'branch_id' => ['type' => 'INTEGER'],
                    'branch_query' => ['type' => 'STRING'],
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
        $branchId = $scope['branch']?->id;

        $sales = DB::table('stock_movements')
            ->selectRaw('product_variant_id, branch_id, GREATEST(0, SUM(-quantity)) AS sold')
            ->whereIn('type', ['sale', 'sale_return'])
            ->where('created_at', '>=', now()->subDays(30))
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->groupBy('product_variant_id', 'branch_id');
        $limit = min(50, max(1, (int) ($args['limit'] ?? 20)));
        $rows = DB::table('stock_levels as sl')
            ->join('product_variants as pv', 'pv.id', '=', 'sl.product_variant_id')
            ->leftJoin('products as p', 'p.id', '=', 'pv.product_id')
            ->leftJoin('branches as b', 'b.id', '=', 'sl.branch_id')
            ->leftJoinSub($sales, 'sales', fn ($join) => $join
                ->on('sales.product_variant_id', '=', 'sl.product_variant_id')->on('sales.branch_id', '=', 'sl.branch_id'))
            ->when($branchId !== null, fn ($q) => $q->where('sl.branch_id', $branchId))
            ->selectRaw('sl.product_variant_id, sl.branch_id, b.name AS branch_name, pv.sku, p.name_ar AS product_name, SUM(sl.available) AS available, MAX(COALESCE(NULLIF(sl.reorder_level, 0), pv.reorder_level, 0)) AS threshold, COALESCE(sales.sold, 0) AS sold')
            ->groupBy('sl.product_variant_id', 'sl.branch_id', 'b.name', 'pv.sku', 'p.name_ar', 'pv.reorder_level', 'sales.sold')
            ->havingRaw('(pv.reorder_level > 0 AND SUM(sl.available) <= pv.reorder_level) OR BOOL_OR(sl.reorder_level > 0 AND sl.available <= sl.reorder_level)')
            ->orderByRaw('SUM(sl.available) / NULLIF(COALESCE(sales.sold, 0) / 30.0, 0) ASC NULLS LAST')
            ->orderBy('sl.product_variant_id')->orderBy('sl.branch_id')
            ->limit($limit)->get();

        $items = $rows->map(function ($r): array {
            $sold = (float) $r->sold;
            $daily = $sold / 30;
            $coverage = $daily > 0 ? round((float) $r->available / $daily, 1) : null;

            return [
                'product_variant_id' => (int) $r->product_variant_id,
                'sku' => $r->sku,
                'product_name' => $this->presenter->untrusted($r->product_name, 150),
                'branch_id' => (int) $r->branch_id,
                'branch_name' => $r->branch_name,
                'available' => (float) $r->available,
                'threshold' => (float) $r->threshold,
                'sold_last_30d' => $sold,
                'coverage_days' => $coverage,
            ];
        })->sortBy(fn ($i) => $i['coverage_days'] ?? 999999)->values();

        $limit = min(50, max(1, (int) ($args['limit'] ?? 20)));
        $items = $items->take($limit)->values()->all();
        $table = array_map(fn ($i) => [
            $i['sku'], $i['branch_name'] ?? (string) $i['branch_id'],
            (string) $i['available'], $i['coverage_days'] !== null ? (string) $i['coverage_days'] : '—',
        ], $items);

        return $this->presenter->present($actor, [
            'low_stock' => $items,
            'count' => count($items),
            'sort' => 'coverage_days',
        ], ['type' => 'table', 'columns' => ['الصنف', 'الفرع', 'المتاح', 'أيام التغطية'], 'rows' => $table]);
    }
}
