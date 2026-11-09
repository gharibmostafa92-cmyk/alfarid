<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\Tools;

use App\Enums\CustomerStatus;
use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\User;
use App\Models\WalletAdjustment;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\Contracts\UndoableTool;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Finance\FinanceArgs;
use App\Services\Ai\Finance\FinancePresenter;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Shared\SettingService;
use App\Services\Finance\WalletAdjustmentService;
use Illuminate\Validation\ValidationException;

final class AdjustCustomerWalletTool implements AgentTool, LocksTargets, UndoableTool, WriteTool
{
    public function __construct(
        private EntityResolver $resolver,
        private FinancePresenter $presenter,
        private ProposalBuilder $proposals,
        private WalletAdjustmentService $adjustments,
        private SettingService $settings,
    ) {}

    public function name(): string
    {
        return 'adjust_customer_wallet';
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
            'description' => 'تعديل يدوي على محفظة عميل (صرف/استرداد/تسوية) بسبب إجباري ومفتاح عدم تكرار.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['kind', 'amount', 'reason', 'idempotency_key'],
                'properties' => (object) [
                    'customer_id' => ['type' => 'INTEGER'],
                    'customer_query' => ['type' => 'STRING'],
                    'kind' => ['type' => 'STRING', 'enum' => ['disbursement', 'refund', 'adjustment']],
                    'amount' => ['type' => 'STRING', 'description' => 'المبلغ كما قاله المستخدم، مثل 1500 أو ألفين.'],
                    'direction' => ['type' => 'STRING', 'enum' => ['credit', 'debit']],
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
        $resolved = $this->resolveCustomer($actor, $args);
        if ($resolved instanceof ToolOutcome) {
            return $resolved;
        }
        $customer = $resolved;
        $kind = (string) ($args['kind'] ?? '');
        if (! in_array($kind, WalletAdjustmentService::KINDS, true)) {
            return ToolOutcome::denied('نوع التعديل لازم يكون disbursement أو refund أو adjustment.', auditable: false);
        }
        try {
            $amount = FinanceArgs::parseAmount($args['amount'] ?? '');
        } catch (ValidationException) {
            return ToolOutcome::denied('المبلغ غير صالح.', auditable: false);
        }
        $direction = $kind === 'disbursement' ? 'debit' : ($kind === 'refund' ? 'credit' : (string) ($args['direction'] ?? ''));
        if (! in_array($direction, WalletAdjustmentService::DIRECTIONS, true)) {
            return ToolOutcome::denied('حدد اتجاه التسوية (credit/debit).', auditable: false);
        }
        if ($direction === 'debit' && $customer->status !== CustomerStatus::Approved) {
            return ToolOutcome::denied('الخصم متاح للمحافظ الخاصة بالعملاء المعتمدين فقط.', auditable: false);
        }
        $reason = mb_substr(trim((string) ($args['reason'] ?? '')), 0, 500);
        if ($reason === '') {
            return ToolOutcome::denied('سبب التعديل إجباري.', auditable: false);
        }
        $key = trim((string) ($args['idempotency_key'] ?? ''));
        if (! preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $key)) {
            return ToolOutcome::denied('مفتاح عدم التكرار مطلوب (حتى 128 حرفًا إنجليزيًا أو رقمًا).', auditable: false);
        }
        $max = (float) $this->settings->get('ai_wallet_adjust_max_amount', 5000);
        if ((float) $amount > $max) {
            return ToolOutcome::denied("المبلغ فوق حد التعديل المباشر ({$max}).", auditable: true);
        }
        $recent = CustomerWalletTransaction::query()->where('customer_id', $customer->id)
            ->orderByDesc('id')->limit(3)->get();
        $latest = $recent->first();
        $current = (string) ($latest?->balance_after ?? $customer->wallet_balance ?? '0.00');
        $signed = $direction === 'credit' ? (float) $amount : -((float) $amount);
        $after = number_format((float) $current + $signed, 2, '.', '');
        $movements = $recent->map(fn ($tx) => [
                'at' => $tx->created_at?->toIso8601String(),
                'type' => (string) $tx->type,
                'type_label' => $this->presenter->walletTypeLabel((string) $tx->type),
                'amount' => (float) $tx->amount,
                'balance_after' => (float) $tx->balance_after,
            ])->all();
        $warnings = [];
        if ($direction === 'debit' && (float) $after <= 0.0) {
            $warnings[] = 'الخصم هيخلي الرصيد صفر.';
        }
        if (WalletAdjustment::query()->where('customer_id', $customer->id)->where('created_at', '>=', now()->subDays(7))->exists()) {
            $warnings[] = 'اتعمل تعديل يدوي للعميل ده في آخر 7 أيام.';
        }
        if ($customer->status !== CustomerStatus::Approved) {
            $warnings[] = 'العميل مش معتمد — راجع الحالة قبل التنفيذ.';
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['customer_id' => $customer->id, 'kind' => $kind, 'amount' => $amount, 'direction' => $direction,
                'reason' => $reason, 'idempotency_key' => $key],
            risk: 'high',
            title: "تعديل محفظة {$customer->name} — {$amount}",
            before: ['wallet_balance' => (float) $current],
            after: ['wallet_balance' => (float) $after, 'kind' => $kind, 'direction' => $direction, 'amount' => (float) $amount],
            warnings: $warnings,
            expectedVersions: ['wallet:'.$customer->id => (int) ($latest?->id ?? 0)],
            extraPreview: ['recent_movements' => $movements],
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        Customer::query()->whereKey((int) ($action->args['customer_id'] ?? 0))->lockForUpdate()->first();
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $a = $action->args;
        $customer = Customer::query()->lockForUpdate()->findOrFail((int) $a['customer_id']);
        if (($a['direction'] ?? null) === 'debit' && $customer->status !== CustomerStatus::Approved) {
            throw new BusinessException('حالة العميل اتغيرت بعد الاقتراح. اطلب اقتراحًا جديدًا.', [], 409);
        }
        $expected = $action->expected_versions['wallet:'.$customer->id] ?? null;
        $latestId = CustomerWalletTransaction::query()->where('customer_id', $customer->id)->max('id');
        if ($expected !== (int) ($latestId ?? 0)) {
            throw new BusinessException('البيانات اتغيرت بعد الاقتراح. اطلب اقتراحًا جديدًا.', [], 409);
        }
        $adjustment = $this->adjustments->adjust([
            'customer_id' => $customer->id, 'kind' => (string) $a['kind'], 'amount' => (string) $a['amount'],
            'direction' => (string) $a['direction'], 'reason' => (string) $a['reason'],
            'source' => 'ai', 'ai_action_id' => $action->id,
        ], $actor, (string) $a['idempotency_key']);
        $action->forceFill(['expected_versions' => ['adjustment:'.$adjustment->id => 'created'] + ($action->expected_versions ?? [])])->save();

        return ToolOutcome::result(true, "اتسجل تعديل المحفظة ({$adjustment->direction}).", [
            'adjustment_id' => $adjustment->id,
            'deep_link' => '#/dealers?customer='.$customer->id.'&tab=wallet',
        ]);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        $adjustment = ($id = $this->createdAdjustmentId($action)) !== null ? WalletAdjustment::find($id) : null;
        if ($adjustment === null || $adjustment->reversals()->exists()) {
            return false;
        }
        if ($adjustment->direction === 'credit'
            && bccomp((string) ($adjustment->customer->wallet_balance ?? '0.00'), (string) $adjustment->amount, 2) < 0) {
            return false;
        }

        return true;
    }

    public function targetState(AiPendingAction $action): string
    {
        $adjustment = WalletAdjustment::findOrFail($this->createdAdjustmentId($action));

        return hash('sha256', json_encode([$adjustment->id, (string) $adjustment->created_at], JSON_THROW_ON_ERROR));
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        $adjustment = WalletAdjustment::findOrFail($this->createdAdjustmentId($action));
        if ($adjustment->reversals()->exists()) {
            throw new BusinessException('التعديل معكوس بالفعل.', [], 409);
        }
        if ($adjustment->direction === 'credit'
            && bccomp((string) ($adjustment->customer->wallet_balance ?? '0.00'), (string) $adjustment->amount, 2) < 0) {
            throw new BusinessException('رصيد المحفظة أقل من مبلغ الإضافة؛ التراجع مش آمن.', [], 409);
        }
        $reversal = $this->adjustments->reverse($adjustment, $actor, 'ai-undo-'.$action->id, 'تراجع تعديل AI');

        return ToolOutcome::result(true, "اترجع تعديل المحفظة بقيد مقابل #{$reversal->id}.", []);
    }

    private function createdAdjustmentId(AiPendingAction $action): ?int
    {
        foreach (array_keys($action->expected_versions ?? []) as $key) {
            if (is_string($key) && str_starts_with($key, 'adjustment:') && is_numeric(mb_substr($key, 11))) {
                return (int) mb_substr($key, 11);
            }
        }

        return isset($action->result['adjustment_id']) ? (int) $action->result['adjustment_id'] : null;
    }

    private function resolveCustomer(User $actor, array $args): Customer|ToolOutcome
    {
        if (! empty($args['customer_id'])) {
            $customer = Customer::find((int) $args['customer_id']);
            if (! $customer) {
                return ToolOutcome::denied('العميل مش موجود.', auditable: false);
            }

            return $customer;
        }
        $query = trim((string) ($args['customer_query'] ?? ''));
        if ($query === '') {
            return ToolOutcome::denied('حدد العميل بـ customer_id أو customer_query.', auditable: false);
        }
        $options = $this->resolver->customers($actor, $query);
        if (count($options) === 0) {
            return ToolOutcome::denied('مش لاقي عميل بالوصف ده.', auditable: false);
        }
        if (count($options) > 1) {
            return ToolOutcome::denied('لقيت أكتر من عميل. استخدم find_entities ثم ask_user_to_choose.', auditable: false);
        }
        $customer = Customer::find($options[0]['id']);

        return $customer ?? ToolOutcome::denied('العميل مش موجود.', auditable: false);
    }
}
