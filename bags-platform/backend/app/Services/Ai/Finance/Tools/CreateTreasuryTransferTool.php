<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\Tools;

use App\Enums\Finance\PosShiftStatus;
use App\Enums\Finance\TreasuryTransferStatus;
use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\Treasury;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\Contracts\UndoableTool;
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
use App\Services\Shared\OperationIdempotencyService;
use App\Services\Shared\SettingService;
use App\Services\Pos\TreasuryService;
use Illuminate\Validation\ValidationException;

final class CreateTreasuryTransferTool implements AgentTool, LocksTargets, UndoableTool, WriteTool
{
    public function __construct(
        private EntityResolver $resolver,
        private BranchAccess $branches,
        private FinancePresenter $presenter,
        private ProposalBuilder $proposals,
        private TreasuryService $treasury,
        private SettingService $settings,
        private OperationIdempotencyService $idempotency,
    ) {}

    public function name(): string
    {
        return 'create_treasury_transfer';
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
        return ['treasury_transfers.create'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'إنشاء مسودة تحويل بين خزينتين. لعكس تحويل معتمد ابعت reverse_transfer_id فقط؛ الأداة تحدد الطرفين والمبلغ من الأصل. التحويل الجديد يبدأ معلقًا.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'reverse_transfer_id' => ['type' => 'INTEGER'],
                    'from_treasury_id' => ['type' => 'INTEGER'],
                    'from_treasury_query' => ['type' => 'STRING'],
                    'to_treasury_id' => ['type' => 'INTEGER'],
                    'to_treasury_query' => ['type' => 'STRING'],
                    'amount' => ['type' => 'STRING', 'description' => 'المبلغ كما قاله المستخدم، مثل 25000 أو ألفين.'],
                    'notes' => ['type' => 'STRING'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = FinanceArgs::normalizeDigitsInArray($args);
        if (isset($args['reverse_transfer_id'])) {
            if (! $actor->canAny(['treasury_transfers.view', 'treasury_transfers.approve'])) {
                return ToolOutcome::denied('معندكش صلاحية الوصول للتحويل الأصلي.');
            }
            $original = FinanceArgs::resolveTreasuryTransfer($actor, $this->resolver, $this->branches, ['transfer_id' => $args['reverse_transfer_id']]);
            if ($original instanceof ToolOutcome) {
                return $original;
            }
            $transfer = $original['transfer'];
            if ($transfer->status !== TreasuryTransferStatus::Approved) {
                return ToolOutcome::denied('التحويل العكسي متاح للتحويل المعتمد فقط.', auditable: false);
            }
            $args = [
                'from_treasury_id' => $transfer->to_treasury_id,
                'to_treasury_id' => $transfer->from_treasury_id,
                'amount' => $transfer->amount,
                'notes' => "تحويل عكسي للتحويل {$transfer->transfer_number}",
            ];
        }
        $from = FinanceArgs::resolveTreasury($actor, $this->resolver, $this->branches, [
            'treasury_id' => $args['from_treasury_id'] ?? null,
            'treasury_query' => $args['from_treasury_query'] ?? '',
        ]);
        if ($from instanceof ToolOutcome) {
            return $from;
        }
        $to = FinanceArgs::resolveTreasury($actor, $this->resolver, $this->branches, [
            'treasury_id' => $args['to_treasury_id'] ?? null,
            'treasury_query' => $args['to_treasury_query'] ?? '',
        ]);
        if ($to instanceof ToolOutcome) {
            return $to;
        }
        $fromTreasury = $from['treasury'];
        $toTreasury = $to['treasury'];
        if ((int) $fromTreasury->id === (int) $toTreasury->id) {
            return ToolOutcome::denied('لا يمكن التحويل من خزينة إلى نفسها.', auditable: false);
        }
        try {
            $amount = FinanceArgs::parseAmount($args['amount'] ?? '');
        } catch (ValidationException $e) {
            return ToolOutcome::denied('المبلغ غير صالح.', auditable: false);
        }
        $fromType = $fromTreasury->type instanceof \BackedEnum ? $fromTreasury->type->value : (string) $fromTreasury->type;
        $warnings = ['لن تتمكن من اعتماد هذا التحويل لأنك منشئه؛ الاعتماد يحتاج موظفًا آخر.'];
        if ($fromType !== 'bank_account' && (float) $fromTreasury->current_balance < (float) $amount) {
            $warnings[] = 'رصيد المصدر الحالي لا يكفي المبلغ — الاعتماد سيفشل ما لم يتغذَّ الرصيد.';
        }
        if (TreasuryTransfer::query()->where('from_treasury_id', $fromTreasury->id)->where('to_treasury_id', $toTreasury->id)
            ->where('amount', $amount)->where('status', TreasuryTransferStatus::Pending)
            ->where('created_at', '>=', now()->subHours(24))->exists()) {
            $warnings[] = 'فيه تحويل معلق بنفس المبلغ والطرفين خلال آخر ٢٤ ساعة.';
        }
        if ($fromType === 'pos_drawer' && $fromTreasury->shifts()->where('status', PosShiftStatus::Open)->exists()) {
            $warnings[] = 'درج المصدر عليه وردية مفتوحة.';
        }
        $highRisk = bccomp($amount, (string) $this->settings->get('ai_finance_high_risk_amount', 20000), 2) > 0;

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['from_treasury_id' => $fromTreasury->id, 'to_treasury_id' => $toTreasury->id, 'amount' => $amount,
                'drawer_surplus' => ($args['drawer_surplus'] ?? false) === true,
                'notes' => isset($args['notes']) ? mb_substr(trim((string) $args['notes']), 0, 500) : null],
            risk: $highRisk ? 'high' : $this->risk(),
            title: "تحويل {$amount} من {$fromTreasury->name} إلى {$toTreasury->name}",
            before: ['status' => 'pending', 'from_balance' => (float) $fromTreasury->current_balance, 'to_balance' => (float) $toTreasury->current_balance],
            after: ['status' => 'pending', 'amount' => (float) $amount,
                'from_balance' => (float) bcsub($fromTreasury->current_balance, $amount, 2),
                'to_balance' => (float) bcadd($toTreasury->current_balance, $amount, 2)],
            warnings: $warnings,
            expectedVersions: [
                'treasury:'.$fromTreasury->id => FinanceWriteGuards::ledgerVersion($fromTreasury),
                'treasury:'.$toTreasury->id => FinanceWriteGuards::ledgerVersion($toTreasury),
            ],
            typedConfirmation: $highRisk ? rtrim(rtrim($amount, '0'), '.') : null,
            typedConfirmationHint: $highRisk ? 'اكتب المبلغ بالأرقام للتأكيد' : null,
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        if ($action->status === 'executed' && ($id = $this->createdTransferId($action)) !== null) {
            (new FinanceLocks)->lockTransfers([$id], $action->user_id);

            return;
        }
        (new FinanceLocks)->lockTargets($action);
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $a = $action->args;
        $from = Treasury::query()->lockForUpdate()->findOrFail((int) $a['from_treasury_id']);
        $to = Treasury::query()->lockForUpdate()->findOrFail((int) $a['to_treasury_id']);
        $this->branches->assertTreasury($actor->id, $from);
        $this->branches->assertTreasury($actor->id, $to);
        if ((int) $from->id === (int) $to->id) {
            throw new BusinessException('لا يمكن التحويل من خزينة إلى نفسها.');
        }
        foreach ([$from, $to] as $treasury) {
            if (($action->expected_versions['treasury:'.$treasury->id] ?? null) !== FinanceWriteGuards::ledgerVersion($treasury)) {
                throw new BusinessException('البيانات اتغيرت بعد الاقتراح. اطلب اقتراحًا جديدًا.', [], 409);
            }
        }
        if ($a['drawer_surplus'] ?? false) {
            $current = collect(app(DailyCloseBriefingTool::class)->surplusTransfers($actor, $from->branch_id))
                ->first(fn (array $row) => $row['from_treasury_id'] === $from->id && $row['to_treasury_id'] === $to->id);
            if ($current === null || bccomp((string) $current['amount'], (string) $a['amount'], 2) < 0) {
                throw new BusinessException('فائض الدرج اتغير بعد الاقتراح. اطلب اقتراحًا جديدًا.', [], 409);
            }
        }
        $data = [
            'from_treasury_id' => $from->id, 'to_treasury_id' => $to->id,
            'amount' => (string) $a['amount'], 'notes' => $a['notes'] ?? null,
        ];
        $transfer = $this->idempotency->run('treasury_transfer', $actor, 'ai:'.$action->id, $data,
            fn () => $this->treasury->createTransfer($data, $actor->id));
        $action->forceFill(['expected_versions' => $action->expected_versions + ['created_transfer_id' => $transfer->id]])->save();

        return ToolOutcome::result(true, "اتعمل تحويل معلق {$transfer->transfer_number}.", [
            'transfer_id' => $transfer->id,
            'transfer_number' => $transfer->transfer_number,
            'deep_link' => $this->presenter->deepLink('trf', $transfer->id),
        ]);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        $id = $this->createdTransferId($action);

        return $id !== null && TreasuryTransfer::find($id)?->status === TreasuryTransferStatus::Pending;
    }

    public function targetState(AiPendingAction $action): string
    {
        $transfer = TreasuryTransfer::findOrFail($this->createdTransferId($action));

        return hash('sha256', $transfer->status->value.':'.$transfer->updated_at);
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        $transfer = TreasuryTransfer::findOrFail($this->createdTransferId($action));
        $this->treasury->rejectTransfer($transfer, $actor->id, 'تراجع من المساعد');

        return ToolOutcome::result(true, "اتلغى طلب التحويل {$transfer->transfer_number}.", [
            'transfer_id' => $transfer->id,
            'deep_link' => $this->presenter->deepLink('trf', $transfer->id),
        ]);
    }

    private function createdTransferId(AiPendingAction $action): ?int
    {
        $id = $action->expected_versions['created_transfer_id'] ?? null;

        return $id === null ? null : (int) $id;
    }
}
