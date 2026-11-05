<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\Tools;

use App\Enums\TreasuryTransferStatus;
use App\Models\PosShift;
use App\Models\TreasuryTransaction;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Finance\FinanceArgs;
use App\Services\Ai\Finance\FinancePresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\BranchAccess;

final class GetTreasuryTool implements AgentTool
{
    public function __construct(
        private EntityResolver $resolver,
        private BranchAccess $branches,
        private FinancePresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'get_treasury';
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
            'description' => 'بطاقة خزينة: الرصيد وحركة النهارده وآخر 10 حركات والتحويلات المعلقة والوردية المفتوحة وقابلية الحذف.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => [],
                'properties' => (object) [
                    'treasury_id' => ['type' => 'INTEGER'],
                    'treasury_query' => ['type' => 'STRING'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = FinanceArgs::normalizeDigitsInArray($args);
        $resolved = FinanceArgs::resolveTreasury($actor, $this->resolver, $this->branches, $args);
        if ($resolved instanceof ToolOutcome) {
            return $resolved;
        }
        $treasury = $resolved['treasury'];
        $type = $treasury->type instanceof \BackedEnum ? $treasury->type->value : (string) $treasury->type;

        $dayStart = now(config('app.business_timezone', 'Africa/Cairo'))->startOfDay();
        $todayIn = (float) TreasuryTransaction::query()->where('treasury_id', $treasury->id)
            ->where('created_at', '>=', $dayStart)->where('amount', '>', 0)->sum('amount');
        $todayOut = (float) TreasuryTransaction::query()->where('treasury_id', $treasury->id)
            ->where('created_at', '>=', $dayStart)->where('amount', '<', 0)->sum('amount');

        $last = TreasuryTransaction::query()->where('treasury_id', $treasury->id)
            ->orderByDesc('id')->limit(10)->get()->map(function (TreasuryTransaction $tx) {
                $txType = $tx->type instanceof \BackedEnum ? $tx->type->value : (string) $tx->type;

                return [
                    'at' => $tx->created_at?->toIso8601String(),
                    'type' => $txType,
                    'type_label' => $this->presenter->transactionTypeLabel($txType),
                    'amount' => (float) $tx->amount,
                    'balance_after' => (float) $tx->balance_after,
                    'reference_label' => $this->presenter->referenceLabel($tx->reference_type, $tx->reference_id),
                    'reference_link' => $this->presenter->referenceLink($tx->reference_type, $tx->reference_id),
                ];
            })->values()->all();

        $pending = \App\Models\TreasuryTransfer::query()
            ->where('status', TreasuryTransferStatus::Pending)
            ->where(fn ($q) => $q->where('from_treasury_id', $treasury->id)->orWhere('to_treasury_id', $treasury->id))
            ->orderBy('id')->get()->map(fn ($t) => [
                'number' => $t->transfer_number,
                'direction' => (int) $t->from_treasury_id === (int) $treasury->id ? 'صادر' : 'وارد',
                'amount' => (float) $t->amount,
            ])->values()->all();

        $openShift = null;
        if ($type === 'pos_drawer') {
            $shift = PosShift::query()->where('treasury_id', $treasury->id)->where('status', 'open')->orderByDesc('id')->first();
            if ($shift) {
                $openShift = ['shift_number' => $shift->shift_number, 'opened_at' => $shift->opened_at?->toIso8601String()];
            }
        }

        $hasTransactions = TreasuryTransaction::query()->where('treasury_id', $treasury->id)->exists();
        $hasShifts = PosShift::query()->where('treasury_id', $treasury->id)->exists();
        $blockers = [];
        if ((float) $treasury->current_balance !== 0.0) {
            $blockers[] = 'الرصيد مش صفر';
        }
        if ($hasTransactions) {
            $blockers[] = 'ليها سجل حركات';
        }
        if ($hasShifts) {
            $blockers[] = 'ليها ورديات';
        }

        $data = [
            'id' => $treasury->id,
            'name' => $this->presenter->untrusted($treasury->name, 150),
            'code' => $treasury->code,
            'type' => $type,
            'type_label' => $this->presenter->treasuryTypeLabel($type),
            'branch' => $treasury->branch?->name,
            'current_balance' => (float) $treasury->current_balance,
            'today_in' => $todayIn,
            'today_out' => abs($todayOut),
            'last_transactions' => $last,
            'pending_transfers' => $pending,
            'open_shift' => $openShift,
            'can_delete' => $blockers === [],
            'delete_blockers' => $blockers,
            'deep_link' => $this->presenter->deepLink('tr', $treasury->id),
        ];

        return $this->presenter->present($actor, ['treasury' => $data], array_merge($data, ['type' => 'treasury_card']));
    }
}
