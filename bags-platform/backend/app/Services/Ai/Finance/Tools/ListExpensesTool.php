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
use App\Services\SearchTerm;

final class ListExpensesTool implements AgentTool
{
    public function __construct(
        private BranchAccess $branches,
        private FinancePresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'list_expenses';
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
        return ['expenses.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'قائمة المصروفات بفلاتر الفرع والخزينة والفئة والفترة والملغي/الساري والوردية.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'branch_id' => ['type' => 'INTEGER'],
                    'treasury_id' => ['type' => 'INTEGER'],
                    'shift_id' => ['type' => 'INTEGER'],
                    'category' => ['type' => 'STRING'],
                    'from_date' => ['type' => 'STRING'],
                    'to_date' => ['type' => 'STRING'],
                    'voided' => ['type' => 'BOOLEAN'],
                    'cursor' => ['type' => 'INTEGER'],
                    'limit' => ['type' => 'INTEGER'],
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

        if (isset($args['treasury_id'])) {
            $treasury = \App\Models\Treasury::find((int) $args['treasury_id']);
            if (! $treasury) {
                return ToolOutcome::denied('الخزينة مش موجودة.', auditable: false);
            }
            try {
                $this->branches->assertTreasury($actor->id, $treasury);
            } catch (\Illuminate\Auth\Access\AuthorizationException) {
                return ToolOutcome::denied('الخزينة خارج نطاقك.', auditable: false);
            }
        }

        $limit = min(50, max(1, (int) ($args['limit'] ?? 20)));
        $rows = Expense::query()->with(['branch:id,name', 'treasury:id,name'])
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when(isset($args['treasury_id']), fn ($q) => $q->where('treasury_id', (int) $args['treasury_id']))
            ->when(isset($args['shift_id']), fn ($q) => $q->where('shift_id', (int) $args['shift_id']))
            ->when(! empty($args['category']), fn ($q) => $q->whereLike('category', SearchTerm::pattern((string) $args['category']), caseSensitive: false))
            ->when(! empty($args['from_date']), fn ($q) => $q->where('expense_date', '>=', (string) $args['from_date']))
            ->when(! empty($args['to_date']), fn ($q) => $q->where('expense_date', '<=', (string) $args['to_date']))
            ->when(array_key_exists('voided', $args), fn ($q) => (bool) $args['voided'] ? $q->whereNotNull('voided_at') : $q->whereNull('voided_at'))
            ->when(! empty($args['cursor']), fn ($q) => $q->where('id', '<', (int) $args['cursor']))
            ->orderByDesc('id')->limit($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->map(fn (Expense $e) => [
            'id' => $e->id,
            'expense_number' => $e->expense_number,
            'branch' => $e->branch?->name,
            'treasury' => $e->treasury?->name,
            'category' => $this->presenter->untrusted($e->category, 191),
            'amount' => (float) $e->amount,
            'expense_date' => $e->expense_date instanceof \DateTimeInterface ? $e->expense_date->format('Y-m-d') : (string) $e->expense_date,
            'voided' => $e->voided_at !== null,
            'deep_link' => $this->presenter->deepLink('exp', $e->id),
        ])->values()->all();

        $table = array_map(fn ($i) => [
            $i['expense_number'],
            is_array($i['category']) ? ($i['category']['untrusted_text'] ?? '') : $i['category'],
            $this->presenter->money($i['amount']),
            $i['expense_date'],
            $i['voided'] ? 'ملغي' : 'ساري',
        ], $items);

        return $this->presenter->present($actor, [
            'expenses' => $items,
            'next_cursor' => $hasMore ? end($items)['id'] ?? null : null,
            'has_more' => $hasMore,
        ], $this->presenter->table(
            ['الرقم', 'الفئة', 'المبلغ', 'التاريخ', 'الحالة'],
            $table
        ));
    }
}
