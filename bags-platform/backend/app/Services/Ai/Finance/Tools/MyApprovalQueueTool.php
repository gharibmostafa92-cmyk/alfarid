<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\Tools;

use App\Enums\TreasuryTransferStatus;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Finance\FinanceArgs;
use App\Services\Ai\Finance\FinancePresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Inventory\BranchAccess;

final class MyApprovalQueueTool implements AgentTool
{
    public function __construct(
        private BranchAccess $branches,
        private FinancePresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'my_approval_queue';
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
        return ['treasury_transfers.approve'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'التحويلات المعلقة اللي تقدر تعتمدها (من غير اللي أنشأتها) مرتبة بالأقدم مع تحذير الرصيد.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
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

        $limit = min(50, max(1, (int) ($args['limit'] ?? 20)));
        $rows = TreasuryTransfer::query()->with(['fromTreasury:id,name,current_balance,type', 'toTreasury:id,name'])
            ->where('status', TreasuryTransferStatus::Pending)
            ->where('created_by', '!=', $actor->id)
            ->when($restricted !== null, fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('fromTreasury', fn ($t) => $t->where('branch_id', $restricted))
                ->orWhereHas('toTreasury', fn ($t) => $t->where('branch_id', $restricted))))
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->map(function (TreasuryTransfer $t) use ($actor) {
            $fromType = $t->fromTreasury?->type instanceof \BackedEnum ? $t->fromTreasury->type->value : (string) ($t->fromTreasury?->type ?? '');
            $sufficient = $fromType === 'bank_account' || (float) ($t->fromTreasury?->current_balance ?? 0) >= (float) $t->amount;

            return [
                'id' => $t->id,
                'transfer_number' => $t->transfer_number,
                'from_treasury' => $t->fromTreasury?->name,
                'to_treasury' => $t->toTreasury?->name,
                'amount' => (float) $t->amount,
                'created_at' => $t->created_at?->toIso8601String(),
                'overdue_24h' => $t->created_at !== null && $t->created_at->lt(now()->subDay()),
                'source_sufficient' => $sufficient,
                'warning' => $sufficient ? null : 'رصيد المصدر مش هيكفي وقت الاعتماد',
                'deep_link' => $this->presenter->deepLink('trf', $t->id),
            ];
        })->values()->all();

        $table = array_map(fn ($i) => [
            $i['transfer_number'],
            ($i['from_treasury'] ?? '—').' ← '.($i['to_treasury'] ?? '—'),
            $this->presenter->money($i['amount']),
            $i['created_at'] ? date('Y-m-d H:i', strtotime($i['created_at'])) : '—',
            $i['warning'] ?? ($i['overdue_24h'] ? 'عدّى 24 ساعة' : '—'),
        ], $items);

        return $this->presenter->present($actor, [
            'queue' => $items,
            'has_more' => $hasMore,
        ], $this->presenter->table(
            ['الرقم', 'المسار', 'المبلغ', 'منذ', 'تنبيه'],
            $table
        ));
    }
}
