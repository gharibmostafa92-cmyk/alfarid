<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\Tools;

use App\Enums\TreasuryTransferStatus;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Finance\FinanceArgs;
use App\Services\Ai\Finance\FinancePresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Inventory\BranchAccess;

final class GetTreasuryTransferTool implements AgentTool
{
    public function __construct(
        private EntityResolver $resolver,
        private BranchAccess $branches,
        private FinancePresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'get_treasury_transfer';
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
            'description' => 'تفاصيل تحويل خزينة: المسار الزمني وأرصدة الطرفين وهل تقدر تعتمده.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'transfer_id' => ['type' => 'INTEGER'],
                    'transfer_query' => ['type' => 'STRING'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = FinanceArgs::normalizeDigitsInArray($args);
        $resolved = FinanceArgs::resolveTreasuryTransfer($actor, $this->resolver, $this->branches, $args);
        if ($resolved instanceof ToolOutcome) {
            return $resolved;
        }
        $transfer = $resolved['transfer'];
        $status = $transfer->status instanceof \BackedEnum ? $transfer->status->value : (string) $transfer->status;
        $transfer->loadMissing(['fromTreasury', 'toTreasury', 'creator', 'approver']);

        $stages = [
            ['stage' => 'created', 'done' => true, 'at' => $transfer->created_at?->toIso8601String(), 'actor' => $transfer->creator?->name],
        ];
        if ($status === TreasuryTransferStatus::Approved->value) {
            $stages[] = ['stage' => 'approved', 'done' => true, 'at' => $transfer->updated_at?->toIso8601String(), 'actor' => $transfer->approver?->name];
        } elseif ($status === TreasuryTransferStatus::Rejected->value) {
            $stages[] = ['stage' => 'rejected', 'done' => true, 'at' => $transfer->updated_at?->toIso8601String(), 'actor' => $transfer->approver?->name];
        } else {
            $stages[] = ['stage' => 'approved', 'done' => false];
        }

        $data = [
            'id' => $transfer->id,
            'transfer_number' => $transfer->transfer_number,
            'from_treasury' => $transfer->fromTreasury?->name,
            'from_balance' => $transfer->fromTreasury ? (float) $transfer->fromTreasury->current_balance : null,
            'to_treasury' => $transfer->toTreasury?->name,
            'to_balance' => $transfer->toTreasury ? (float) $transfer->toTreasury->current_balance : null,
            'amount' => (float) $transfer->amount,
            'status' => $status,
            'created_by' => $transfer->creator?->name,
            'notes' => $transfer->notes !== null ? $this->presenter->untrusted($transfer->notes, 300) : null,
            'rejection_reason' => $transfer->rejection_reason !== null ? $this->presenter->untrusted($transfer->rejection_reason, 300) : null,
            'can_approve' => $status === TreasuryTransferStatus::Pending->value
                && (int) $transfer->created_by !== (int) $actor->id
                && $actor->can('treasury_transfers.approve'),
            'deep_link' => $this->presenter->deepLink('trf', $transfer->id),
        ];

        return $this->presenter->present($actor, ['transfer' => $data], [
            'type' => 'transfer_timeline',
            'stages' => $stages,
            'deep_link' => $data['deep_link'],
        ]);
    }
}
