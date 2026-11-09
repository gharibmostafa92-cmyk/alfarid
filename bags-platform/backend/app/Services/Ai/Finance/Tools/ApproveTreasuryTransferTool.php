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
use App\Services\Inventory\BranchAccess;
use App\Services\Shared\SettingService;
use App\Services\Pos\TreasuryService;

final class ApproveTreasuryTransferTool implements AgentTool, LocksTargets, WriteTool
{
    public function __construct(
        private EntityResolver $resolver,
        private BranchAccess $branches,
        private FinancePresenter $presenter,
        private ProposalBuilder $proposals,
        private TreasuryService $treasury,
        private SettingService $settings,
        private FinanceWriteGuards $guards,
    ) {}

    public function name(): string
    {
        return 'approve_treasury_transfer';
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
            'description' => 'اعتماد تحويل معلق وتحريك الأرصدة. منشئ التحويل لا يستطيع اعتماده.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'transfer_id' => ['type' => 'INTEGER'],
                    'transfer_query' => ['type' => 'STRING'],
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
            return ToolOutcome::denied('الاعتماد من التحويلات المعلقة فقط.', auditable: false);
        }
        if ((int) $transfer->created_by === (int) $actor->id) {
            return ToolOutcome::denied('لا يمكنك اعتماد تحويل أنشأته بنفسك — الفصل بين المنشئ والمعتمد إجباري.', auditable: true);
        }
        $transfer->loadMissing(['fromTreasury', 'toTreasury']);
        $warnings = [];
        $fromType = $transfer->fromTreasury?->type instanceof \BackedEnum ? $transfer->fromTreasury->type->value : (string) ($transfer->fromTreasury?->type ?? '');
        if ($fromType !== 'bank_account' && (float) ($transfer->fromTreasury?->current_balance ?? 0) < (float) $transfer->amount) {
            $shortfall = (float) $transfer->amount - (float) ($transfer->fromTreasury?->current_balance ?? 0);
            $from = $transfer->fromTreasury;
            $topUp = $from ? $this->guards->suggestTopUp($from->branch_id, $from->id, $shortfall, (string) $from->name) : null;
            $warnings[] = 'رصيد المصدر الحالي لا يكفي — الاعتماد سيفشل ما لم يتغذَّ الرصيد قبل التأكيد.'
                .($topUp === null ? '' : " {$topUp['name']} فيها {$topUp['balance']} — قل «{$topUp['prompt']}» لتغطية العجز بضغطة.");
        }
        $threshold = (float) $this->settings->get('ai_finance_high_risk_amount', 20000);
        $risk = (float) $transfer->amount > $threshold ? 'high' : $this->risk();

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['transfer_id' => $transfer->id],
            risk: $risk,
            title: "اعتماد تحويل {$transfer->transfer_number}",
            before: ['status' => $status, 'from_balance' => (float) ($transfer->fromTreasury?->current_balance ?? 0), 'to_balance' => (float) ($transfer->toTreasury?->current_balance ?? 0)],
            after: ['status' => TreasuryTransferStatus::Approved->value, 'amount' => (float) $transfer->amount],
            warnings: $warnings,
            expectedVersions: [self::versionKey($transfer) => FinanceWriteGuards::transferVersion($transfer)],
            typedConfirmation: $risk === 'high' ? $transfer->transfer_number : null,
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
        $transfer = TreasuryTransfer::query()->findOrFail((int) $action->args['transfer_id']);
        foreach ([$transfer->fromTreasury, $transfer->toTreasury] as $treasury) {
            $this->branches->assertTreasury($actor->id, $treasury);
        }
        $expected = $action->expected_versions[self::versionKey($transfer)] ?? null;
        if ($expected !== FinanceWriteGuards::transferVersion($transfer)) {
            throw new BusinessException('التحويل اتغير بعد الاقتراح. اطلب اقتراحًا جديدًا.', [], 409);
        }
        try {
            $updated = $this->treasury->approveTransfer($transfer, $actor->id);
        } catch (BusinessException $e) {
            if ($e->status === 403 || str_contains($e->getMessage(), 'أنشأته بنفسك')) {
                throw new BusinessException($e->getMessage(), [], 403);
            }
            if (str_contains($e->getMessage(), 'رصيد الخزينة غير كافٍ')) {
                $from = $transfer->fromTreasury;
                $shortfall = (float) $transfer->amount - (float) ($from?->current_balance ?? 0);
                $topUp = $from ? $this->guards->suggestTopUp($from->branch_id, $from->id, $shortfall, (string) $from->name) : null;
                $message = $e->getMessage().($topUp === null ? '' : " {$topUp['name']} فيها {$topUp['balance']} — قل «{$topUp['prompt']}» لتغطية العجز بضغطة.");
                throw new BusinessException($message, [], $e->status);
            }
            throw $e;
        }

        $message = "اتعتمد التحويل {$updated->transfer_number} واتحركت الأرصدة.";
        $data = [
            'transfer_id' => $updated->id,
            'transfer_number' => $updated->transfer_number,
            'deep_link' => $this->presenter->deepLink('trf', $updated->id),
        ];
        $block = ['type' => 'result', 'ok' => true, 'message' => $message, 'deep_link' => $data['deep_link']];
        if ($actor->can('treasury_transfers.create')) {
            $block['quick_actions'] = [[
                'label' => 'اقترح تحويل عكسي',
                'prompt' => "جهّز تحويل خزينة عكسي للتحويل المعتمد رقم {$updated->id}",
                'submit' => true,
            ]];
        }

        return ToolOutcome::result(true, $message, $data, $block);
    }

    public static function versionKey(TreasuryTransfer $transfer): string
    {
        return 'treasury_transfer:'.$transfer->id;
    }
}
