<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\Tools;

use App\Enums\TreasuryTransferStatus;
use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Finance\FinanceArgs;
use App\Services\Ai\Finance\FinanceLocks;
use App\Services\Ai\Finance\FinancePresenter;
use App\Services\Ai\Finance\FinanceWriteGuards;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\BranchAccess;
use App\Services\TreasuryService;

final class RejectTreasuryTransferTool implements AgentTool, LocksTargets, WriteTool
{
    public function __construct(
        private EntityResolver $resolver,
        private BranchAccess $branches,
        private FinancePresenter $presenter,
        private ProposalBuilder $proposals,
        private TreasuryService $treasury,
    ) {}

    public function name(): string
    {
        return 'reject_treasury_transfer';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Write;
    }

    public function risk(): string
    {
        return 'medium';
    }

    public function permissions(): array
    {
        return ['treasury_transfers.approve'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'رفض تحويل معلق بسبب اختياري. الرفض لا يحرك الأرصدة.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'transfer_id' => ['type' => 'INTEGER'],
                    'transfer_query' => ['type' => 'STRING'],
                    'reason' => ['type' => 'STRING'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = FinanceArgs::normalizeDigitsInArray($args);
        $resolved = FinanceArgs::resolveTreasuryTransfer($actor, $this->resolver, $this->branches, $args);
        if ($resolved instanceof ToolOutcome) {
            return $resolved;
        }
        $transfer = $resolved['transfer'];
        $status = $transfer->status instanceof \BackedEnum ? $transfer->status->value : (string) $transfer->status;
        if ($status !== TreasuryTransferStatus::Pending->value) {
            return ToolOutcome::denied('الرفض من التحويلات المعلقة فقط.', auditable: false);
        }
        $reason = isset($args['reason']) ? mb_substr(trim((string) $args['reason']), 0, 500) : null;

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['transfer_id' => $transfer->id, 'reason' => $reason],
            risk: $this->risk(),
            title: "رفض تحويل {$transfer->transfer_number}",
            before: ['status' => $status],
            after: ['status' => TreasuryTransferStatus::Rejected->value],
            expectedVersions: [ApproveTreasuryTransferTool::versionKey($transfer) => FinanceWriteGuards::transferVersion($transfer)],
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        $transfer = TreasuryTransfer::find((int) ($action->args['transfer_id'] ?? 0));
        if ($transfer) {
            (new FinanceLocks)->lockTransfers([$transfer->id], $action->user_id);
        }
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $a = $action->args;
        $transfer = TreasuryTransfer::query()->findOrFail((int) $a['transfer_id']);
        foreach ([$transfer->fromTreasury, $transfer->toTreasury] as $treasury) {
            $this->branches->assertTreasury($actor->id, $treasury);
        }
        $expected = $action->expected_versions[ApproveTreasuryTransferTool::versionKey($transfer)] ?? null;
        if ($expected !== FinanceWriteGuards::transferVersion($transfer)) {
            throw new BusinessException('التحويل اتغير بعد الاقتراح. اطلب اقتراحًا جديدًا.', [], 409);
        }
        $updated = $this->treasury->rejectTransfer($transfer, $actor->id, $a['reason'] ?? null);

        return ToolOutcome::result(true, "اترفض التحويل {$updated->transfer_number} من غير ما تتحرك الأرصدة.", [
            'transfer_id' => $updated->id,
            'transfer_number' => $updated->transfer_number,
            'deep_link' => $this->presenter->deepLink('trf', $updated->id),
        ]);
    }
}
