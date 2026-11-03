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
use App\Services\ReportService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 5.2: كارت الصباح للمخزون — بيتبني في PHP بالكامل، من غير أي نداء
 * لـ Gemini (صفر توكنز). كل سطر جنبه زرار "اتصرف" بprompt جاهز.
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
        return Cache::remember('ai:inventory-welcome:'.$actor->id, 60, function () use ($actor): ?array {
            try {
                $restricted = $this->branches->restrictedBranch($actor);
            } catch (\Throwable) {
                return null;
            }

            $rows = [];
            $actions = [];

            $pending = StockTransfer::query()
                ->where('status', TransferStatus::Draft)
                ->where('created_at', '<', now()->subDay())
                ->when($restricted !== null, fn ($q) => $q->where(fn ($w) => $w->where('from_branch_id', $restricted)->orWhere('to_branch_id', $restricted)))
                ->count();
            if ($pending > 0) {
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
            if ($openStocktakes > 0) {
                $rows[] = ['label' => 'جرود مفتوحة من أسبوع', 'value' => $openStocktakes];
                $actions[] = ['label' => 'الجرود المفتوحة', 'prompt' => 'ايه الجرود المفتوحة؟'];
            }

            $run = InventoryReconcileRun::query()
                ->when($restricted !== null, fn ($q) => $q->where('branch_id', $restricted))
                ->orderByDesc('id')->first();
            if ($run) {
                $age = $run->created_at?->diffForHumans() ?? '';
                $rows[] = ['label' => 'آخر مطابقة ('.$age.')', 'value' => $run->problem_count === 0 ? 'سليمة' : $run->problem_count.' فروقات'];
                if ($run->problem_count > 0) {
                    $actions[] = ['label' => 'شوف الفروقات', 'prompt' => 'نتيجة آخر مطابقة للأرصدة'];
                }
            }

            $dead = $this->deadValue($restricted);
            $threshold = (float) app(\App\Services\SettingService::class)->get('ai_inventory_high_risk_value', 50000);
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
        $levels = DB::table('stock_levels as sl')
            ->join('product_variants as pv', 'pv.id', '=', 'sl.product_variant_id')
            ->when($restricted !== null, fn ($q) => $q->where('sl.branch_id', $restricted))
            ->whereRaw('COALESCE(NULLIF(sl.reorder_level, 0), pv.reorder_level, 0) > 0')
            ->whereRaw('sl.available <= COALESCE(NULLIF(sl.reorder_level, 0), pv.reorder_level, 0)')
            ->selectRaw('sl.product_variant_id, sl.branch_id, sl.available')
            ->orderBy('sl.available')
            ->limit(200)->get();
        if ($levels->isEmpty()) {
            return 0;
        }
        $sales = DB::table('stock_movements')
            ->selectRaw('product_variant_id, branch_id, SUM(-quantity) AS sold')
            ->where('type', 'sale')
            ->where('created_at', '>=', now()->subDays(30))
            ->whereIn('product_variant_id', $levels->pluck('product_variant_id')->all())
            ->groupBy('product_variant_id', 'branch_id')
            ->get()->keyBy(fn ($r) => $r->product_variant_id.':'.$r->branch_id);
        $count = 0;
        foreach ($levels as $l) {
            $sold = (float) ($sales->get($l->product_variant_id.':'.$l->branch_id)?->sold ?? 0);
            if ($sold > 0 && (float) $l->available / ($sold / 30) <= 3) {
                $count++;
            }
        }

        return $count;
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
