<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\Tools;

use App\Models\FinanceReconcileRun;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Finance\FinanceArgs;
use App\Services\Ai\Finance\FinancePresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Inventory\BranchAccess;

final class LedgerIntegrityCheckTool implements AgentTool
{
    public function __construct(
        private BranchAccess $branches,
        private FinancePresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'ledger_integrity_check';
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
        return ['finance.reports.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'آخر نتيجة مطابقة للأرصدة مع الدفتر (خزائن ومحافظ) من التشغيل الليلي.',
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

        $run = FinanceReconcileRun::query()
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId), fn ($q) => $q->whereNull('branch_id'))
            ->orderByDesc('id')->first();

        if (! $run) {
            return $this->presenter->present($actor, [
                'has_run' => false,
            ], null, 'مفيش مطابقة مسجلة لسه. شغّل finance:reconcile من المجدول الليلي.');
        }

        $samples = array_slice($run->samples ?? [], 0, 20);
        $items = [];
        if ((int) $run->problem_count === 0) {
            $items[] = ['key' => 'ok', 'label' => 'كل الأرصدة مطابقة للدفتر', 'severity' => 'ok'];
        }
        foreach ($samples as $i => $problem) {
            $items[] = [
                'key' => ($problem['issue'] ?? 'diff').':'.$i,
                'label' => $this->label($problem),
                'severity' => 'critical',
                'detail' => 'المتوقع '.($problem['expected'] ?? '?').' والفعلي '.($problem['actual'] ?? '?'),
            ];
        }

        return $this->presenter->present($actor, [
            'has_run' => true,
            'checked' => (int) $run->checked,
            'problem_count' => (int) $run->problem_count,
            'samples' => $samples,
            'ran_at' => $run->created_at?->toIso8601String(),
        ], $this->presenter->checklist($items));
    }

    /** @param array<string,mixed> $problem */
    private function label(array $problem): string
    {
        $issue = (string) ($problem['issue'] ?? '');
        if ($issue === 'treasury_chain' || $issue === 'treasury_balance') {
            $id = (int) ($problem['treasury_id'] ?? $problem['ref_id'] ?? 0);

            return 'خزينة #'.$id.' — '.($issue === 'treasury_chain' ? 'سلسلة الحركات مكسورة' : 'الرصيد لا يساوي مجموع الحركات').' '.$this->presenter->deepLink('tr', $id);
        }
        $id = (int) ($problem['customer_id'] ?? $problem['ref_id'] ?? 0);

        return 'محفظة عميل #'.$id.' — '.($issue === 'wallet_chain' ? 'سلسلة الحركات مكسورة' : 'الرصيد لا يساوي مجموع الحركات').' #/customers/'.$id.'?tab=wallet';
    }
}
