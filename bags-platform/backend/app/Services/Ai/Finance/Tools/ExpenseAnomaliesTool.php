<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\Tools;

use App\Models\Expense;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Finance\FinanceArgs;
use App\Services\Ai\Finance\FinancePresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\BranchAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ExpenseAnomaliesTool implements AgentTool
{
    private const WINDOW_DAYS = 30;

    private const CATEGORY_LOOKBACK_DAYS = 90;

    private const SECTION_LIMIT = 10;

    public function __construct(
        private BranchAccess $branches,
        private FinancePresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'expense_anomalies';
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
        return ['expenses.view', 'finance.reports.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'شواذ المصروفات آخر 30 يوم: مكرر محتمل، قفزة عن متوسط الفئة، أرقام مدورة متكررة، تسجيل بعد مواعيد العمل، فئات جديدة.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'branch_id' => ['type' => 'INTEGER'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = FinanceArgs::normalizeDigitsInArray($args);
        try {
            $restricted = $this->branches->restrictedBranch($actor);
        } catch (\Throwable) {
            return ToolOutcome::denied('النطاق غير مسموح.', auditable: false);
        }
        if ($restricted !== null && isset($args['branch_id']) && (int) $args['branch_id'] !== $restricted) {
            return ToolOutcome::denied('الفرع خارج نطاقك.', auditable: false);
        }
        $branchId = $restricted ?? (isset($args['branch_id']) ? (int) $args['branch_id'] : null);
        $from = now()->subDays(self::WINDOW_DAYS)->toDateString();

        $base = fn () => Expense::query()->whereNull('voided_at')->where('expense_date', '>=', $from)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId));

        $duplicates = $this->duplicates($base, $branchId);
        $jumps = $this->categoryJumps($base, $branchId, $from);
        $round = $this->roundRepeated($base);
        $afterHours = $this->afterHours($base);
        $newCategories = $this->newCategories($branchId);

        $sections = [
            'duplicates' => $duplicates,
            'category_jumps' => $jumps,
            'round_repeated' => $round,
            'after_hours' => $afterHours,
            'new_categories' => $newCategories,
        ];
        $items = [];
        foreach ($sections as $key => $entries) {
            foreach (array_slice($entries, 0, self::SECTION_LIMIT) as $entry) {
                $items[] = [
                    'key' => $key.':'.$entry['id'],
                    'label' => $entry['title'],
                    'severity' => 'warning',
                    'detail' => $entry['detail'].' '.$entry['link'],
                ];
            }
        }
        if ($items === []) {
            $items[] = ['key' => 'ok', 'label' => 'مفيش شواذ في مصروفات آخر 30 يوم', 'severity' => 'ok'];
        }

        return $this->presenter->present($actor, [
            'window_days' => self::WINDOW_DAYS,
            'sections' => array_map(fn ($entries) => array_slice($entries, 0, self::SECTION_LIMIT), $sections),
        ], $this->presenter->checklist($items));
    }

    /** @return list<array{id:int,title:string,detail:string,link:string}> */
    private function duplicates(\Closure $base, ?int $branchId): array
    {
        $groups = $base()
            ->selectRaw('branch_id, category, amount, expense_date, COUNT(*) AS c, MIN(id) AS first_id')
            ->groupBy('branch_id', 'category', 'amount', 'expense_date')
            ->havingRaw('COUNT(*) > 1')->orderByDesc('c')->limit(self::SECTION_LIMIT)->get();

        $out = [];
        foreach ($groups as $g) {
            $ids = $base()->where('branch_id', $g->branch_id)->where('category', $g->category)
                ->where('amount', $g->amount)->where('expense_date', $g->expense_date)
                ->orderBy('id')->pluck('expense_number', 'id');
            $first = Expense::query()->find($g->first_id);
            $out[] = [
                'id' => (int) $g->first_id,
                'title' => "مكرر محتمل: {$g->c} مصروفات {$g->category} بنفس المبلغ في نفس اليوم",
                'detail' => 'الأرقام: '.implode('، ', $ids->values()->all()),
                'link' => $this->presenter->deepLink('exp', (int) $g->first_id),
            ];
        }

        return $out;
    }

    /** @return list<array{id:int,title:string,detail:string,link:string}> */
    private function categoryJumps(\Closure $base, ?int $branchId, string $from): array
    {
        $averages = Expense::query()->whereNull('voided_at')
            ->where('expense_date', '>=', now()->subDays(self::CATEGORY_LOOKBACK_DAYS)->toDateString())
            ->where('expense_date', '<', $from)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('category, AVG(amount) AS avg_amount, COUNT(*) AS c')
            ->groupBy('category')->havingRaw('COUNT(*) >= 2')->pluck('avg_amount', 'category');

        $out = [];
        foreach ($base()->orderByDesc('amount')->limit(200)->get(['id', 'expense_number', 'category', 'amount']) as $expense) {
            $avg = (float) ($averages[$expense->category] ?? 0);
            if ($avg > 0 && (float) $expense->amount > $avg * 3) {
                $out[] = [
                    'id' => $expense->id,
                    'title' => "قفزة في {$expense->category}: {$expense->expense_number}",
                    'detail' => 'المبلغ '.(float) $expense->amount.' مقابل متوسط '.round($avg, 2),
                    'link' => $this->presenter->deepLink('exp', $expense->id),
                ];
            }
            if (count($out) >= self::SECTION_LIMIT) {
                break;
            }
        }

        return $out;
    }

    /** @return list<array{id:int,title:string,detail:string,link:string}> */
    private function roundRepeated(\Closure $base): array
    {
        $groups = $base()
            ->selectRaw('created_by, amount, COUNT(*) AS c, MIN(id) AS first_id')
            ->groupBy('created_by', 'amount')->havingRaw('COUNT(*) > 1')
            ->orderByDesc('c')->limit(50)->get();

        $out = [];
        foreach ($groups as $g) {
            if (fmod((float) $g->amount, 1000) !== 0.0) {
                continue;
            }
            $first = Expense::query()->find($g->first_id);
            $out[] = [
                'id' => (int) $g->first_id,
                'title' => "رقم مدوّر متكرر: {$g->c} مصروفات بمبلغ ".(float) $g->amount.' من نفس المستخدم',
                'detail' => 'أولها '.($first?->expense_number ?? '#'.$g->first_id),
                'link' => $this->presenter->deepLink('exp', (int) $g->first_id),
            ];
            if (count($out) >= self::SECTION_LIMIT) {
                break;
            }
        }

        return $out;
    }

    /** @return list<array{id:int,title:string,detail:string,link:string}> */
    private function afterHours(\Closure $base): array
    {
        $tz = (string) config('app.business_timezone', 'Africa/Cairo');
        $out = [];
        foreach ($base()->orderByDesc('id')->limit(200)->get(['id', 'expense_number', 'created_at']) as $expense) {
            $hour = $expense->created_at ? Carbon::parse($expense->created_at)->setTimezone($tz)->hour : null;
            if ($hour !== null && ($hour < 8 || $hour >= 23)) {
                $out[] = [
                    'id' => $expense->id,
                    'title' => "تسجيل بعد مواعيد العمل: {$expense->expense_number}",
                    'detail' => 'اتسجل الساعة '.Carbon::parse($expense->created_at)->setTimezone($tz)->format('H:i'),
                    'link' => $this->presenter->deepLink('exp', $expense->id),
                ];
            }
            if (count($out) >= self::SECTION_LIMIT) {
                break;
            }
        }

        return $out;
    }

    /** @return list<array{id:int,title:string,detail:string,link:string}> */
    private function newCategories(?int $branchId): array
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $old = Expense::query()->whereNull('voided_at')->where('expense_date', '<', $monthStart)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->distinct()->pluck('category')->all();

        $out = [];
        foreach (Expense::query()->whereNull('voided_at')->where('expense_date', '>=', $monthStart)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->whereNotIn('category', $old === [] ? ['__none__'] : $old)
            ->selectRaw('category, COUNT(*) AS c, MIN(id) AS first_id')
            ->groupBy('category')->orderByDesc('c')->limit(self::SECTION_LIMIT)->get() as $g) {
            $out[] = [
                'id' => (int) $g->first_id,
                'title' => "فئة جديدة الشهر ده: {$g->category} ({$g->c})",
                'detail' => 'أول ظهور الشهر الحالي',
                'link' => $this->presenter->deepLink('exp', (int) $g->first_id),
            ];
        }

        return $out;
    }
}
