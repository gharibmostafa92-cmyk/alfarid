<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\Tools;

use App\Exceptions\BusinessException;
use App\Models\AiAttachment;
use App\Models\AiPendingAction;
use App\Models\Expense;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Contracts\ExecutesOutsideTransaction;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\Contracts\UndoableTool;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Finance\ExpenseCategoryNormalizer;
use App\Services\Ai\Finance\FinanceArgs;
use App\Services\Ai\Finance\FinanceLocks;
use App\Services\Ai\Finance\FinancePresenter;
use App\Services\Ai\Finance\FinanceWriteGuards;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\BranchAccess;
use App\Services\ExpenseService;
use App\Services\SettingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class CreateExpenseTool implements AgentTool, ExecutesOutsideTransaction, LocksTargets, UndoableTool, WriteTool
{
    public function __construct(
        private EntityResolver $resolver,
        private BranchAccess $branches,
        private FinancePresenter $presenter,
        private ProposalBuilder $proposals,
        private ExpenseService $expenses,
        private SettingService $settings,
        private FinanceWriteGuards $guards,
        private ExpenseCategoryNormalizer $categories,
    ) {}

    public function name(): string
    {
        return 'create_expense';
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
        return ['expenses.create'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'تسجيل مصروف إداري على خزينة (الفئة والمبلغ يقبلان الصيغ المنطوقة).',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['category', 'amount'],
                'properties' => (object) [
                    'branch_id' => ['type' => 'INTEGER'],
                    'treasury_id' => ['type' => 'INTEGER'],
                    'treasury_query' => ['type' => 'STRING'],
                    'category' => ['type' => 'STRING'],
                    'amount' => ['type' => 'STRING', 'description' => 'المبلغ كما قاله المستخدم، مثل 1500 أو ألفين.'],
                    'expense_date' => ['type' => 'STRING'],
                    'notes' => ['type' => 'STRING'],
                    'attachment_id' => ['type' => 'STRING'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = FinanceArgs::normalizeDigitsInArray($args);
        try {
            $restricted = $this->branches->restrictedBranch($actor);
        } catch (AuthorizationException) {
            return ToolOutcome::denied('الفرع خارج نطاقك.', auditable: false);
        }
        if ($restricted !== null && empty($args['treasury_id']) && empty($args['treasury_query'])) {
            $safes = Treasury::query()->where('branch_id', $restricted)->where('type', 'main_safe')
                ->where('is_active', true)->orderBy('id')->limit(8)->get(['id', 'name', 'code']);
            if ($safes->count() > 1) {
                return ToolOutcome::picker([
                    'type' => 'entity_picker',
                    'entity' => 'treasury',
                    'purpose' => 'اختار الخزينة اللي يتسجل عليها المصروف',
                    'multi' => false,
                    'options' => $safes->map(fn (Treasury $safe) => ['id' => $safe->id, 'label' => $safe->name, 'meta' => $safe->code])->all(),
                ]);
            }
            $args['treasury_id'] = $safes->first()?->id;
        }
        $resolved = FinanceArgs::resolveTreasury($actor, $this->resolver, $this->branches, [
            'treasury_id' => $args['treasury_id'] ?? null,
            'treasury_query' => $args['treasury_query'] ?? '',
        ]);
        if ($resolved instanceof ToolOutcome) {
            return $resolved;
        }
        $treasury = $resolved['treasury'];
        $branchId = isset($args['branch_id']) ? (int) $args['branch_id'] : $treasury->branch_id;
        if ($branchId === null) {
            return ToolOutcome::denied('حدد الفرع للمصروف.', auditable: false);
        }
        try {
            $this->branches->assertBranch($actor->id, (int) $branchId);
        } catch (AuthorizationException) {
            return ToolOutcome::denied('الفرع خارج نطاقك.', auditable: false);
        }
        $category = mb_substr(trim((string) ($args['category'] ?? '')), 0, 191);
        if ($category === '') {
            return ToolOutcome::denied('فئة المصروف مطلوبة.', auditable: false);
        }
        try {
            $amount = FinanceArgs::parseAmount($args['amount'] ?? '');
        } catch (ValidationException) {
            return ToolOutcome::denied('المبلغ غير صالح.', auditable: false);
        }
        $now = now(config('app.business_timezone', 'Africa/Cairo'));
        $date = trim((string) ($args['expense_date'] ?? ''));
        if ($date === '') {
            $date = $now->toDateString();
        } elseif (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return ToolOutcome::denied('تاريخ المصروف بصيغة YYYY-MM-DD.', auditable: false);
        }
        $attachmentPath = null;
        if (! empty($args['attachment_id'])) {
            $attachmentPath = $this->attachmentPath($actor, (string) $args['attachment_id']);
            if ($attachmentPath === null) {
                return ToolOutcome::denied('المرفق مش موجود أو لا يخص هذه المحادثة.', auditable: false);
            }
        }
        $warnings = [];
        $type = $treasury->type instanceof \BackedEnum ? $treasury->type->value : (string) $treasury->type;
        if ($type === 'pos_drawer') {
            return ToolOutcome::denied('مصروفات أدراج نقطة البيع تُسجل من مسار نقطة البيع فقط.', auditable: false);
        }
        if (! $treasury->is_active || ($treasury->branch_id !== null && (int) $treasury->branch_id !== (int) $branchId)) {
            return ToolOutcome::denied('الخزينة غير مفعّلة أو لا تتبع الفرع المحدد.', auditable: false);
        }
        $normalized = $this->categories->normalize($category, (int) $branchId);
        $category = $normalized['category'];
        if ($normalized['is_new']) {
            $warnings[] = "فئة جديدة: «{$category}». راجعها قبل التأكيد.";
        }
        $similar = Expense::query()->where('branch_id', $branchId)->where('category', $category)->whereNull('voided_at');
        if ((clone $similar)->where('treasury_id', $treasury->id)->where('amount', $amount)
            ->where('created_at', '>=', now()->subHours(48))->exists()) {
            $warnings[] = 'فيه مصروف مشابه بنفس المبلغ والخزينة خلال آخر ٤٨ ساعة.';
        }
        $average = (float) (clone $similar)->where('expense_date', '>=', $now->copy()->subMonthsNoOverflow(6)->toDateString())->avg('amount');
        if ($average > 0 && (float) $amount > 3 * $average) {
            $warnings[] = 'المبلغ أكبر من ٣ أضعاف متوسط الفئة.';
        }
        if ($date > $now->toDateString() || $date < $now->copy()->subDays(30)->toDateString()) {
            $warnings[] = 'تاريخ المصروف في المستقبل أو أقدم من ٣٠ يوم.';
        }
        $threshold = (float) $this->settings->get('ai_finance_high_risk_amount', 20000);
        if ((float) $amount > (float) $treasury->current_balance && $type !== 'bank_account') {
            $shortfall = (float) $amount - (float) $treasury->current_balance;
            $topUp = $this->guards->suggestTopUp($treasury->branch_id, $treasury->id, $shortfall, (string) $treasury->name);
            $warnings[] = 'المبلغ أكبر من رصيد الخزينة الحالي — التسجيل سيفشل ما لم يتغذَّ الرصيد.'
                .($topUp === null ? '' : " {$topUp['name']} فيها {$topUp['balance']} — قل «{$topUp['prompt']}» لتغطية العجز بضغطة.");
        }
        $risk = (float) $amount > $threshold ? 'high' : $this->risk();

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['branch_id' => (int) $branchId, 'treasury_id' => $treasury->id, 'category' => $category,
                'amount' => $amount, 'expense_date' => $date,
                'notes' => isset($args['notes']) ? mb_substr(trim((string) $args['notes']), 0, 500) : null,
                'attachment_path' => $attachmentPath],
            risk: $risk,
            title: "مصروف {$category} — {$amount}",
            before: ['treasury_balance' => (float) $treasury->current_balance],
            after: ['category' => $category, 'amount' => (float) $amount, 'expense_date' => $date],
            warnings: $warnings,
            expectedVersions: ['treasury:'.$treasury->id => FinanceWriteGuards::ledgerVersion($treasury)],
            typedConfirmation: $risk === 'high' ? 'تنفيذ' : null,
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        if ($action->status === 'executed' && ($id = $this->createdExpenseId($action)) !== null) {
            Expense::query()->lockForUpdate()->findOrFail($id);
        }
        (new FinanceLocks)->lockTargets($action);
    }

    public function executesOutsideTransaction(AiPendingAction $action): bool
    {
        return ! empty($action->args['attachment_path']);
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $source = $action->args['attachment_path'] ?? null;
        if ($source === null) {
            return $this->recordExpense($actor, $action, null);
        }

        $disk = Storage::disk('ai_private');
        $receiptPath = "expense-receipts/{$action->id}/".basename($source);
        try {
            if (! $disk->copy($source, $receiptPath)) {
                throw new \League\Flysystem\UnableToCopyFile('Receipt copy failed.');
            }
        } catch (\League\Flysystem\FilesystemException $exception) {
            report($exception);
            throw new BusinessException('تعذّر حفظ إيصال المصروف. أعد رفعه وحاول مرة أخرى.', [], 422);
        }

        try {
            return DB::transaction(function () use ($actor, $action, $receiptPath): ToolOutcome {
                $claimed = AiPendingAction::query()->lockForUpdate()->findOrFail($action->id);
                if ($claimed->status !== 'confirmed') {
                    throw new BusinessException('الإجراء لم يعد متاحًا. اطلب اقتراحًا جديدًا.', [], 409);
                }

                return $this->recordExpense($actor, $claimed, $receiptPath);
            });
        } catch (\Throwable $exception) {
            try {
                $disk->delete($receiptPath);
            } catch (\Throwable $cleanupError) {
                report($cleanupError);
            }
            throw $exception;
        }
    }

    private function recordExpense(User $actor, AiPendingAction $action, ?string $receiptPath): ToolOutcome
    {
        $a = $action->args;
        $treasury = Treasury::query()->lockForUpdate()->findOrFail((int) $a['treasury_id']);
        if (($action->expected_versions['treasury:'.$treasury->id] ?? null) !== FinanceWriteGuards::ledgerVersion($treasury)) {
            throw new BusinessException('البيانات اتغيرت بعد الاقتراح. اطلب اقتراحًا جديدًا.', [], 409);
        }
        try {
            $expense = $this->expenses->create([
                'branch_id' => (int) $a['branch_id'], 'treasury_id' => (int) $a['treasury_id'],
                'category' => (string) $a['category'], 'amount' => (string) $a['amount'],
                'expense_date' => (string) $a['expense_date'], 'notes' => $a['notes'] ?? null,
                'attachment_path' => $receiptPath,
            ], $actor, 'ai-action-'.$action->id);
        } catch (BusinessException $e) {
            if (str_contains($e->getMessage(), 'رصيد الخزينة غير كافٍ')) {
                throw new BusinessException($this->insufficientMessage((int) $a['treasury_id'], (string) $a['amount']), [], $e->status);
            }
            throw $e;
        }
        // The expense only exists after execution — persist its reference in
        // expected_versions (the same slot other tools use for created refs)
        // so canUndo/targetState/undo can find it after finalize.
        $action->forceFill(['expected_versions' => ['expense:'.$expense->id => 'created'] + ($action->expected_versions ?? [])])->save();

        return ToolOutcome::result(true, "اتسجل المصروف {$expense->expense_number}.", [
            'expense_id' => $expense->id,
            'expense_number' => $expense->expense_number,
            'deep_link' => $this->presenter->deepLink('exp', $expense->id),
        ]);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        $expense = ($id = $this->createdExpenseId($action)) !== null ? Expense::find($id) : null;

        return $expense !== null && $expense->voided_at === null && $expense->shift_id === null;
    }

    public function targetState(AiPendingAction $action): string
    {
        $expense = Expense::findOrFail($this->createdExpenseId($action));

        return hash('sha256', json_encode([$expense->id, (string) $expense->voided_at], JSON_THROW_ON_ERROR));
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        $expense = Expense::findOrFail($this->createdExpenseId($action));
        if ($expense->voided_at !== null) {
            throw new BusinessException('المصروف ملغي بالفعل.', [], 409);
        }
        $this->expenses->void($expense, 'تراجع إجراء AI', $actor);

        return ToolOutcome::result(true, "اتلغى المصروف {$expense->expense_number} بقيد عكسي.", []);
    }

    private function insufficientMessage(int $treasuryId, string $amount): string
    {
        $treasury = \App\Models\Treasury::find($treasuryId);
        $base = 'رصيد الخزينة غير كافٍ لإتمام العملية.';
        if ($treasury === null) {
            return $base;
        }
        $shortfall = (float) $amount - (float) $treasury->current_balance;
        $topUp = $this->guards->suggestTopUp($treasury->branch_id, $treasury->id, $shortfall, (string) $treasury->name);
        if ($topUp === null) {
            return $base;
        }

        return $base." {$topUp['name']} فيها {$topUp['balance']} — قل «{$topUp['prompt']}» لتغطية العجز بضغطة.";
    }

    private function createdExpenseId(AiPendingAction $action): ?int
    {
        foreach (array_keys($action->expected_versions ?? []) as $key) {
            if (is_string($key) && str_starts_with($key, 'expense:') && is_numeric(mb_substr($key, 8))) {
                return (int) mb_substr($key, 8);
            }
        }

        return isset($action->result['expense_id']) ? (int) $action->result['expense_id'] : null;
    }

    private function attachmentPath(User $actor, string $attachmentId): ?string
    {
        $conv = $this->proposals->conversation();
        if (! $conv) {
            return null;
        }
        $attachment = AiAttachment::query()->where('user_id', $actor->id)
            ->where('conversation_id', $conv->id)->where('id', $attachmentId)->first();

        return $attachment?->path;
    }
}
