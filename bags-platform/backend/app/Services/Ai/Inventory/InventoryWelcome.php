<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory;

use App\Enums\StocktakeStatus;
use App\Enums\TransferStatus;
use App\Models\InventoryReconcileRun;
use App\Models\Stocktake;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\CostAccess;
use App\Services\ReportService;
use App\Services\SettingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Morning inventory digest card: deterministic PHP aggregation (0 LLM tokens) with quick action prompts.
 */
final class InventoryWelcome
{
    public function __construct(
        private BranchAccess $branches,
        private ReportService $reports,
    ) {}

    /** @return array<string,mixed>|null */
    public function card(User $actor): ?array
    {
        try {
            $restricted = $this->branches->restrictedBranch($actor);
        } catch (\Throwable) {
            return null;
        }
        $access = [$restricted, $actor->can('transfers.view'), $actor->can('stocktakes.view'), CostAccess::canView($actor)];

        return Cache::remember('ai:inventory-welcome:'.$actor->id.':'.hash('sha256', json_encode($access)), 60, function () use ($actor, $restricted): ?array {

            $rows = [];
            $actions = [];

            $pending = StockTransfer::query()
                ->where('status', TransferStatus::Draft)
                ->where('created_at', '<', now()->subDay())
                ->when($restricted !== null, fn ($q) => $q->where(fn ($w) => $w->where('from_branch_id', $restricted)->orWhere('to_branch_id', $restricted)))
                ->count();
            if ($actor->can('transfers.view') && $pending > 0) {
                $rows[] = ['label' => 'تحويلات مستنية الاعتماد من أكتر من 24 ساعة', 'value' => $pending];
                $actions[] = ['label' => 'اعرض المعلقة', 'prompt' => 'اعرض التحويلات المسودة اللي مستنية الاعتماد'];
            }

            $low = $this->lowCoverageCount($restricted);
            if ($low > 0) {
                $rows[] = ['label' => 'أصناف هتخلص خلال 3 أيام', 'value' => $low];
                $actions[] = ['label' => 'النواقص', 'prompt' => 'ايه الأصناف اللي هتخلص خلال 3 أيام؟'];
            }

            $openStocktakes = Stocktake::query()
                ->whereIn('status', [StocktakeStatus::Draft, StocktakeStatus::InProgress])
                ->where('created_at', '<', now()->subWeek())
                ->when($restricted !== null, fn ($q) => $q->where('branch_id', $restricted))
                ->count();
            if ($actor->can('stocktakes.view') && $openStocktakes > 0) {
                $rows[] = ['label' => 'جرود مفتوحة من أسبوع', 'value' => $openStocktakes];
                $actions[] = ['label' => 'الجرود المفتوحة', 'prompt' => 'ايه الجرود المفتوحة؟'];
            }

            $run = InventoryReconcileRun::query()
                ->when($restricted !== null, fn ($q) => $q->where('branch_id', $restricted), fn ($q) => $q->whereNull('branch_id'))
                ->orderByDesc('id')->first();
            if ($run) {
                $age = $run->created_at?->diffForHumans() ?? '';
                $rows[] = ['label' => 'آخر مطابقة ('.$age.')', 'value' => $run->problem_count === 0 ? 'سليمة' : $run->problem_count.' فروقات'];
                if ($run->problem_count > 0) {
                    $actions[] = ['label' => 'شوف الفروقات', 'prompt' => 'نتيجة آخر مطابقة للأرصدة'];
                }
            }

            $dead = CostAccess::canView($actor) ? $this->deadValue($restricted) : null;
            $threshold = (float) app(SettingService::class)->get('ai_inventory_high_risk_value', 50000);
            if ($dead !== null && $dead > $threshold) {
                $rows[] = ['label' => 'قيمة الراكد فوق الحد', 'value' => $dead];
                $actions[] = ['label' => 'الرواكد', 'prompt' => 'ايه البضاعة الراكدة وقيمتها؟'];
            }

            if ($rows === []) {
                return null;
            }

            return ['type' => 'welcome_card', 'title' => 'ملخص المخزون الصباحي', 'rows' => $rows, 'quick_actions' => $actions];
        });
    }

    private function lowCoverageCount(?int $restricted): int
    {
        $levels = DB::table('stock_levels')
            ->when($restricted !== null, fn ($q) => $q->where('branch_id', $restricted))
            ->selectRaw('product_variant_id, branch_id, SUM(available) AS available')
            ->groupBy('product_variant_id', 'branch_id');
        $sales = DB::table('stock_movements')
            ->selectRaw('product_variant_id, branch_id, GREATEST(0, SUM(-quantity)) AS sold')
            ->whereIn('type', ['sale', 'sale_return'])
            ->where('created_at', '>=', now()->subDays(30))
            ->when($restricted !== null, fn ($q) => $q->where('branch_id', $restricted))
            ->groupBy('product_variant_id', 'branch_id');

        return DB::query()->fromSub($levels, 'levels')
            ->joinSub($sales, 'sales', fn ($join) => $join->on('sales.product_variant_id', '=', 'levels.product_variant_id')->on('sales.branch_id', '=', 'levels.branch_id'))
            ->where('sales.sold', '>', 0)->whereRaw('levels.available <= sales.sold / 30.0 * 3')->count();
    }

    private function deadValue(?int $restricted): ?float
    {
        try {
            $report = $this->reports->inventoryReport(90, $restricted);
        } catch (\Throwable) {
            return null;
        }

        return isset($report['dead_stock']) ? (float) array_sum(array_column($report['dead_stock'], 'tied_value')) : null;
    }
}
