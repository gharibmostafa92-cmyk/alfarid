<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\Tools;

use App\Services\Shared\SearchTerm;

use App\Enums\Finance\TreasuryType;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Finance\FinanceArgs;
use App\Services\Ai\Finance\FinancePresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Inventory\BranchAccess;

final class ListTreasuriesTool implements AgentTool
{
    public function __construct(
        private BranchAccess $branches,
        private FinancePresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'list_treasuries';
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
        return ['treasuries.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'قائمة الخزائن (الاسم والكود والنوع والرصيد وآخر حركة) بفلاتر الفرع والنوع والنشطة.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'query' => ['type' => 'STRING'],
                    'branch_id' => ['type' => 'INTEGER'],
                    'type' => ['type' => 'STRING', 'enum' => array_column(TreasuryType::cases(), 'value')],
                    'is_active' => ['type' => 'BOOLEAN'],
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

        $limit = min(50, max(1, (int) ($args['limit'] ?? 20)));
        $rows = Treasury::query()->with(['branch:id,name', 'latestTransaction'])
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when(! empty($args['type']), fn ($q) => $q->where('type', $args['type']))
            ->when(array_key_exists('is_active', $args), fn ($q) => $q->where('is_active', (bool) $args['is_active']))
            ->when(! empty($args['query']), function ($q) use ($args): void {
                $pattern = \App\Services\Shared\SearchTerm::pattern((string) $args['query']);
                $q->where(fn ($w) => $w->where('name', 'ilike', $pattern)->orWhere('code', 'ilike', $pattern));
            })
            ->orderBy('branch_id')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->map(function (Treasury $t) {
            $type = $t->type instanceof \BackedEnum ? $t->type->value : (string) $t->type;
            $last = $t->latestTransaction;

            return [
                'id' => $t->id,
                'name' => $this->presenter->untrusted($t->name, 150),
                'code' => $t->code,
                'type' => $type,
                'type_label' => $this->presenter->treasuryTypeLabel($type),
                'branch' => $t->branch?->name,
                'current_balance' => (float) $t->current_balance,
                'is_active' => (bool) $t->is_active,
                'last_transaction' => $last ? [
                    'at' => $last->created_at?->toIso8601String(),
                    'type' => $last->type instanceof \BackedEnum ? $last->type->value : (string) $last->type,
                    'amount' => (float) $last->amount,
                    'balance_after' => (float) $last->balance_after,
                ] : null,
                'deep_link' => $this->presenter->deepLink('tr', $t->id),
            ];
        })->values()->all();

        $table = array_map(fn ($i) => [
            (is_array($i['name']) ? ($i['name']['untrusted_text'] ?? '') : $i['name']).' ('.$i['code'].')',
            $i['branch'] ?? 'مركزية',
            $i['type_label'],
            $this->presenter->money($i['current_balance']),
            $i['is_active'] ? 'نشطة' : 'متوقفة',
        ], $items);

        return $this->presenter->present($actor, [
            'treasuries' => $items,
            'has_more' => $hasMore,
        ], $this->presenter->table(
            ['الخزينة', 'الفرع', 'النوع', 'الرصيد', 'الحالة'],
            $table
        ));
    }
}
