<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance;

use App\Enums\TreasuryTransferStatus;
use App\Models\Expense;
use App\Models\PosShift;
use App\Models\Treasury;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Services\Inventory\BranchAccess;
use App\Services\Shared\SettingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Morning finance card — built entirely in PHP with zero Gemini calls.
 * Every row carries a quick action with a ready-made prompt.
 */
final class FinanceWelcome
{
    public function __construct(
        private BranchAccess $branches,
        private SettingService $settings,
    ) {}

    /** @return array<string,mixed>|null */
    public function card(User $actor): ?array
    {
        try {
            $restricted = $this->branches->restrictedBranch($actor);
        } catch (\Throwable) {
            return null;
        }
        $access = [$restricted, $actor->can('treasuries.view'), $actor->can('treasury_transfers.approve'), $actor->can('expenses.view'), $actor->can('customers.wallet.view'), $actor->can('finance.reports.view')];

        return Cache::remember('ai:finance-welcome:'.$actor->id.':'.hash('sha256', json_encode($access)), 60, function () use ($actor, $restricted): ?array {
            $rows = [];
            $actions = [];

            if ($actor->can('treasury_transfers.approve')) {
                $pending = $this->approvableTransfers($actor, $restricted);
                $count = $pending->count();
                if ($count > 0) {
                    $overdue = (clone $pending)->where('treasury_transfers.created_at', '<', now()->subHours(48))->count();
                    if ($overdue > 0) {
                        array_unshift($rows, ['label' => "تحويلات عدّى عليها أكتر من 48 ساعة ومستنية اعتمادك", 'value' => $overdue]);
                        array_unshift($actions, ['label' => 'اعرض التحويلات المتأخرة اللي مستنية اعتمادي', 'prompt' => 'اعرض التحويلات اللي مستنية اعتمادي']);
                    }
                    $rows[] = ['label' => 'تحويلات مستنية اعتمادك'.($overdue > 0 ? " (منها {$overdue} عدّى عليها أكتر من 48 ساعة)" : ''), 'value' => $count];
                    $actions[] = ['label' => 'اعرض التحويلات اللي مستنية اعتمادي', 'prompt' => 'اعرض التحويلات اللي مستنية اعتمادي'];
                }
            }

            if ($actor->can('treasuries.view')) {
                $openShifts = PosShift::query()
                    ->where('status', 'closed_pending_approval')
                    ->when($restricted !== null, fn ($q) => $q->where('branch_id', $restricted))
                    ->count();
                if ($openShifts > 0) {
                    $rows[] = ['label' => 'الورديات اللي مستنية التسوية', 'value' => $openShifts];
                    $actions[] = ['label' => 'الورديات اللي مستنية التسوية', 'prompt' => 'الورديات اللي مستنية التسوية'];
                }
            }

            if ($actor->can('expenses.view')) {
                $yesterday = $this->expenseSum($restricted, now()->subDay()->toDateString(), now()->subDay()->toDateString());
                $monthTotal = $this->expenseSum(
                    $restricted,
                    now()->subDays(30)->toDateString(),
                    now()->subDay()->toDateString()
                );
                $average = $monthTotal / 30;
                if ($yesterday > 0 && $average > 0 && $yesterday > $average * 1.5) {
                    $rows[] = ['label' => 'مصروفات امبارح أعلى من متوسط آخر 30 يوم بـ '.round((($yesterday - $average) / $average) * 100).'%', 'value' => $yesterday];
                    $actions[] = ['label' => 'ليه مصروفات امبارح عالية؟', 'prompt' => 'ليه مصروفات امبارح عالية؟'];
                }
            }

            if ($actor->can('treasuries.view')) {
                $lowCash = $this->lowCashTreasuries($restricted);
                if ($lowCash > 0) {
                    $rows[] = ['label' => 'خزائن كاش رصيدها أقل من 10% من متوسطها', 'value' => $lowCash];
                    $actions[] = ['label' => 'الخزائن اللي رصيدها قليل', 'prompt' => 'الخزائن اللي رصيدها قليل'];
                }
            }

            if ($actor->can('finance.reports.view') && class_exists('App\\Models\\FinanceReconcileRun')) {
                $run = app('App\\Models\\FinanceReconcileRun')::query()
                    ->when($restricted !== null, fn ($q) => $q->where('branch_id', $restricted), fn ($q) => $q->whereNull('branch_id'))
                    ->orderByDesc('id')->first();
                if ($run) {
                    $age = $run->created_at?->diffForHumans() ?? '';
                    $rows[] = ['label' => 'آخر فحص سلامة للدفتر ('.$age.')', 'value' => $run->problem_count === 0 ? 'سليمة' : $run->problem_count.' فروقات'];
                    if ($run->problem_count > 0) {
                        $actions[] = ['label' => 'نتيجة فحص الأرصدة', 'prompt' => 'نتيجة فحص الأرصدة'];
                    }
                }
            }

            if ($rows === []) {
                return null;
            }

            return ['type' => 'welcome_card', 'domain' => 'finance', 'title' => 'ملخص المالية الصباحي', 'rows' => $rows, 'quick_actions' => $actions];
        });
    }

    private function approvableTransfers(User $actor, ?int $restricted)
    {
        return TreasuryTransfer::query()
            ->where('status', TreasuryTransferStatus::Pending)
            ->where('created_by', '!=', $actor->id)
            ->when($restricted !== null, fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('fromTreasury', fn ($t) => $t->where('branch_id', $restricted))
                ->orWhereHas('toTreasury', fn ($t) => $t->where('branch_id', $restricted))));
    }

    private function expenseSum(?int $restricted, string $from, string $to): float
    {
        return (float) Expense::query()
            ->whereNull('voided_at')
            ->whereBetween('expense_date', [$from, $to])
            ->when($restricted !== null, fn ($q) => $q->where('branch_id', $restricted))
            ->sum('amount');
    }

    private function lowCashTreasuries(?int $restricted): int
    {
        $ids = Treasury::query()
            ->where('is_active', true)
            ->whereIn('type', ['main_safe', 'pos_drawer'])
            ->when($restricted !== null, fn ($q) => $q->where('branch_id', $restricted))
            ->pluck('id', 'id');
        if ($ids->isEmpty()) {
            return 0;
        }
        $averages = DB::table('treasury_transactions')
            ->selectRaw('treasury_id, AVG(balance_after) AS avg_balance')
            ->whereIn('treasury_id', $ids->all())
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('treasury_id')
            ->pluck('avg_balance', 'treasury_id');

        $low = 0;
        foreach (Treasury::query()->whereKey($ids->all())->get(['id', 'current_balance']) as $treasury) {
            $average = (float) ($averages[$treasury->id] ?? 0);
            if ($average > 0 && (float) $treasury->current_balance < $average * 0.1) {
                $low++;
            }
        }

        return $low;
    }
}
