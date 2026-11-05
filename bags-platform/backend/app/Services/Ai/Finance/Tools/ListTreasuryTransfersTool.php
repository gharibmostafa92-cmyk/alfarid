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
use App\Services\BranchAccess;

final class ListTreasuryTransfersTool implements AgentTool
{
    public function __construct(
        private BranchAccess $branches,
        private FinancePresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'list_treasury_transfers';
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
        return ['treasury_transfers.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'قائمة تحويلات الخزائن بفلاتر الحالة والخزينة (صادر أو وارد) والفترة والحد الأدنى للمبلغ.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'status' => ['type' => 'STRING', 'enum' => array_column(TreasuryTransferStatus::cases(), 'value')],
                    'treasury_id' => ['type' => 'INTEGER'],
                    'from_date' => ['type' => 'STRING'],
                    'to_date' => ['type' => 'STRING'],
                    'min_amount' => ['type' => 'NUMBER'],
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

        $treasuryId = isset($args['treasury_id']) ? (int) $args['treasury_id'] : null;
        if ($treasuryId !== null) {
            $treasury = \App\Models\Treasury::find($treasuryId);
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
        $rows = TreasuryTransfer::query()->with(['fromTreasury:id,name', 'toTreasury:id,name', 'creator:id,name'])
            ->when($restricted !== null, fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('fromTreasury', fn ($t) => $t->where('branch_id', $restricted))
                ->orWhereHas('toTreasury', fn ($t) => $t->where('branch_id', $restricted))))
            ->when(! empty($args['status']), fn ($q) => $q->where('status', $args['status']))
            ->when($treasuryId !== null, fn ($q) => $q->where(fn ($w) => $w->where('from_treasury_id', $treasuryId)->orWhere('to_treasury_id', $treasuryId)))
            ->when(! empty($args['from_date']), fn ($q) => $q->where('created_at', '>=', (string) $args['from_date'].' 00:00:00'))
            ->when(! empty($args['to_date']), fn ($q) => $q->where('created_at', '<=', (string) $args['to_date'].' 23:59:59'))
            ->when(! empty($args['min_amount']), fn ($q) => $q->where('amount', '>=', (string) $args['min_amount']))
            ->when(! empty($args['cursor']), fn ($q) => $q->where('id', '<', (int) $args['cursor']))
            ->orderByDesc('id')->limit($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->map(function (TreasuryTransfer $t) {
            $status = $t->status instanceof \BackedEnum ? $t->status->value : (string) $t->status;

            return [
                'id' => $t->id,
                'transfer_number' => $t->transfer_number,
                'from_treasury' => $t->fromTreasury?->name,
                'to_treasury' => $t->toTreasury?->name,
                'amount' => (float) $t->amount,
                'status' => $status,
                'created_by' => $t->creator?->name,
                'created_at' => $t->created_at?->toIso8601String(),
                'deep_link' => $this->presenter->deepLink('trf', $t->id),
            ];
        })->values()->all();

        $table = array_map(fn ($i) => [
            $i['transfer_number'],
            ($i['from_treasury'] ?? '—').' ← '.($i['to_treasury'] ?? '—'),
            $this->presenter->money($i['amount']),
            $i['status'],
        ], $items);

        return $this->presenter->present($actor, [
            'transfers' => $items,
            'next_cursor' => $hasMore ? end($items)['id'] ?? null : null,
            'has_more' => $hasMore,
        ], $this->presenter->table(
            ['الرقم', 'المسار', 'المبلغ', 'الحالة'],
            $table
        ));
    }
}
