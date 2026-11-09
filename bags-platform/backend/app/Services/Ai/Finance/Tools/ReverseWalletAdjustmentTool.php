<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\Tools;

use App\Enums\StaffRole;
use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\User;
use App\Models\WalletAdjustment;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\Contracts\UndoableTool;
use App\Services\Ai\Finance\FinanceArgs;
use App\Services\Ai\Finance\FinancePresenter;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Shared\SettingService;
use App\Services\Finance\WalletAdjustmentService;

final class ReverseWalletAdjustmentTool implements AgentTool, LocksTargets, UndoableTool, WriteTool
{
    private const WINDOW_MINUTES = 15;

    public function __construct(
        private FinancePresenter $presenter,
        private ProposalBuilder $proposals,
        private WalletAdjustmentService $adjustments,
        private SettingService $settings,
    ) {}

    public function name(): string
    {
        return 'reverse_wallet_adjustment';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Write;
    }

    public function risk(): string
    {
        return 'high';
    }

    public function permissions(): array
    {
        return ['customers.wallet.adjust'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'عكس تعديل محفظة بقيد مقابل خلال 15 دقيقة من إنشائه.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['adjustment_id', 'idempotency_key'],
                'properties' => (object) [
                    'adjustment_id' => ['type' => 'INTEGER'],
                    'reason' => ['type' => 'STRING'],
                    'idempotency_key' => ['type' => 'STRING'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = FinanceArgs::normalizeDigitsInArray($args);
        if (! $this->settings->getBool('ai_finance_wallet_tools_enabled', false)) {
            return ToolOutcome::denied('أدوات المحفظة مقفولة من الإعدادات.', auditable: false);
        }
        $adjustment = WalletAdjustment::find((int) ($args['adjustment_id'] ?? 0));
        if (! $adjustment) {
            return ToolOutcome::denied('التعديل مش موجود.', auditable: false);
        }
        if ($adjustment->reversals()->exists()) {
            return ToolOutcome::denied('هذا التعديل معكوس بالفعل.', auditable: false);
        }
        if ($adjustment->created_at !== null && $adjustment->created_at->lt(now()->subMinutes(self::WINDOW_MINUTES))) {
            return ToolOutcome::denied('لا يمكن عكس تعديل مر عليه أكثر من 15 دقيقة.', auditable: false);
        }
        if (! $this->mayReverse($actor, $adjustment)) {
            return ToolOutcome::denied('فقط منشئ التعديل أو مدير عام يمكنه عكسه.', auditable: true);
        }
        $key = trim((string) ($args['idempotency_key'] ?? ''));
        if (! preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $key)) {
            return ToolOutcome::denied('مفتاح عدم التكرار مطلوب (حتى 128 حرفًا إنجليزيًا أو رقمًا).', auditable: false);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['adjustment_id' => $adjustment->id,
                'reason' => isset($args['reason']) ? mb_substr(trim((string) $args['reason']), 0, 500) : null,
                'idempotency_key' => $key],
            risk: $this->risk(),
            title: "عكس تعديل المحفظة #{$adjustment->id}",
            before: ['adjustment_id' => $adjustment->id, 'direction' => $adjustment->direction, 'amount' => (float) $adjustment->amount],
            after: ['reversed' => true],
            expectedVersions: ['wallet:'.$adjustment->customer_id => $this->walletVersion($adjustment->customer_id)],
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        WalletAdjustment::query()->whereKey((int) ($action->args['adjustment_id'] ?? 0))->lockForUpdate()->first();
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $a = $action->args;
        $adjustment = WalletAdjustment::query()->lockForUpdate()->findOrFail((int) $a['adjustment_id']);
        Customer::query()->lockForUpdate()->findOrFail($adjustment->customer_id);
        if (($action->expected_versions['wallet:'.$adjustment->customer_id] ?? null) !== $this->walletVersion($adjustment->customer_id)) {
            throw new BusinessException('البيانات اتغيرت بعد الاقتراح. اطلب اقتراحًا جديدًا.', [], 409);
        }
        if ($adjustment->reversals()->exists()) {
            throw new BusinessException('هذا التعديل معكوس بالفعل.', [], 409);
        }
        if ($adjustment->created_at !== null && $adjustment->created_at->lt(now()->subMinutes(self::WINDOW_MINUTES))) {
            throw new BusinessException('لا يمكن عكس تعديل مر عليه أكثر من 15 دقيقة.', [], 409);
        }
        if (! $this->mayReverse($actor, $adjustment)) {
            throw new BusinessException('فقط منشئ التعديل أو مدير عام يمكنه عكسه.', [], 403);
        }
        $reversal = $this->adjustments->reverse($adjustment, $actor, (string) $a['idempotency_key'],
            isset($a['reason']) ? (string) $a['reason'] : null);
        $action->forceFill(['expected_versions' => ['reversal:'.$reversal->id => 'created'] + ($action->expected_versions ?? [])])->save();

        return ToolOutcome::result(true, "اتعكس التعديل #{$adjustment->id} بقيد مقابل.", [
            'adjustment_id' => $adjustment->id,
            'reversal_id' => $reversal->id,
            'deep_link' => '#/dealers?customer='.$adjustment->customer_id.'&tab=wallet',
        ]);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        $id = $this->createdReversalId($action);

        return $id !== null && WalletAdjustment::find($id) !== null;
    }

    public function targetState(AiPendingAction $action): string
    {
        $reversal = WalletAdjustment::findOrFail($this->createdReversalId($action));

        return hash('sha256', json_encode([$reversal->id, (string) $reversal->created_at], JSON_THROW_ON_ERROR));
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        $reversal = WalletAdjustment::findOrFail($this->createdReversalId($action));
        if ($reversal->reversals()->exists()) {
            throw new BusinessException('العكس ملغي بالفعل.', [], 409);
        }
        if (! $this->mayReverse($actor, $reversal)) {
            throw new BusinessException('فقط منشئ العكس أو مدير عام يمكنه التراجع عنه.', [], 403);
        }
        $reReversal = $this->adjustments->reverse($reversal, $actor, 'ai-undo-'.$action->id, 'تراجع عكس AI');

        return ToolOutcome::result(true, "اترجع عكس التعديل بقيد مقابل #{$reReversal->id}.", []);
    }

    private function mayReverse(User $actor, WalletAdjustment $adjustment): bool
    {
        return (int) $adjustment->created_by === (int) $actor->id
            || (method_exists($actor, 'hasRole') && $actor->hasRole(StaffRole::SuperAdmin->value, 'staff'));
    }

    private function walletVersion(int $customerId): int
    {
        return (int) (CustomerWalletTransaction::query()->where('customer_id', $customerId)->max('id') ?? 0);
    }

    private function createdReversalId(AiPendingAction $action): ?int
    {
        foreach (array_keys($action->expected_versions ?? []) as $key) {
            if (is_string($key) && str_starts_with($key, 'reversal:') && is_numeric(mb_substr($key, 9))) {
                return (int) mb_substr($key, 9);
            }
        }

        return isset($action->result['reversal_id']) ? (int) $action->result['reversal_id'] : null;
    }
}
