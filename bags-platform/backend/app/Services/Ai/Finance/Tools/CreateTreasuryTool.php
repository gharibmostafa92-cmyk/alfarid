<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\Tools;

use App\Enums\Finance\TreasuryType;
use App\Models\AiPendingAction;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\Finance\FinanceArgs;
use App\Services\Ai\Finance\FinanceLocks;
use App\Services\Ai\Finance\FinancePresenter;
use App\Services\Ai\Finance\FinanceWriteGuards;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Pos\TreasuryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class CreateTreasuryTool implements AgentTool, LocksTargets, WriteTool
{
    public function __construct(
        private FinanceWriteGuards $guards,
        private FinancePresenter $presenter,
        private ProposalBuilder $proposals,
        private TreasuryService $treasury,
    ) {}

    public function name(): string
    {
        return 'create_treasury';
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
        return ['treasuries.create'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'إنشاء خزينة (اسم/كود/نوع/فرع/نشطة). نوع الخزينة ثابت بعد الإنشاء.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['name', 'code', 'type'],
                'properties' => (object) [
                    'name' => ['type' => 'STRING'],
                    'code' => ['type' => 'STRING'],
                    'type' => ['type' => 'STRING', 'enum' => array_column(TreasuryType::cases(), 'value')],
                    'branch_id' => ['type' => 'INTEGER'],
                    'is_active' => ['type' => 'BOOLEAN'],
                    'initial_balance' => ['type' => 'NUMBER'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = FinanceArgs::normalizeDigitsInArray($args);
        $name = trim((string) ($args['name'] ?? ''));
        $code = trim((string) ($args['code'] ?? ''));
        $type = (string) ($args['type'] ?? '');
        if ($name === '' || mb_strlen($name) > 191) {
            return ToolOutcome::denied('اسم الخزينة مطلوب (حتى 191 حرفًا).', auditable: false);
        }
        if (! preg_match('/^[A-Za-z0-9_-]+$/', $code) || mb_strlen($code) > 50) {
            return ToolOutcome::denied('الكود مطلوب وحروف وأرقام وشرطات فقط.', auditable: false);
        }
        if (! in_array($type, array_column(TreasuryType::cases(), 'value'), true)) {
            return ToolOutcome::denied('نوع الخزينة غير معروف.', auditable: false);
        }
        if (Treasury::query()->where('code', $code)->exists()) {
            return ToolOutcome::denied('الكود مستخدم بالفعل.', auditable: false);
        }
        $branchId = isset($args['branch_id']) ? (int) $args['branch_id'] : null;
        try {
            $blocker = $this->guards->creationBlocker($actor, $branchId, $type);
        } catch (AuthorizationException $e) {
            return ToolOutcome::denied($e->getMessage(), auditable: false);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolOutcome::denied('الفرع مش موجود.', auditable: false);
        }
        if ($blocker !== null) {
            return ToolOutcome::denied($blocker, auditable: false);
        }
        $initial = (string) ($args['initial_balance'] ?? '0');
        try {
            $amount = FinanceArgs::parseAmount($initial === '' ? '0' : $initial);
        } catch (ValidationException) {
            return ToolOutcome::denied('الرصيد الافتتاحي لازم يكون رقم موجب.', auditable: false);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['name' => $name, 'code' => $code, 'type' => $type, 'branch_id' => $branchId, 'is_active' => (bool) ($args['is_active'] ?? true), 'initial_balance' => $amount],
            risk: $this->risk(),
            title: "إنشاء خزينة {$name} ({$code})",
            before: ['treasuries_count' => Treasury::query()->count()],
            after: ['name' => $name, 'code' => $code, 'type' => $type],
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        (new FinanceLocks)->lockBranches($action);
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $a = $action->args;
        $blocker = $this->guards->creationBlocker($actor, $a['branch_id'] ?? null, (string) $a['type']);
        if ($blocker !== null) {
            throw new \App\Exceptions\BusinessException($blocker);
        }
        if (Treasury::query()->where('code', (string) $a['code'])->exists()) {
            throw new \App\Exceptions\BusinessException('الكود مستخدم بالفعل.', [], 409);
        }
        $treasury = $this->treasury->createAccount([
            'name' => $a['name'], 'code' => $a['code'], 'branch_id' => $a['branch_id'] ?? null,
            'type' => $a['type'], 'is_active' => (bool) ($a['is_active'] ?? true),
        ], $actor, (string) ($a['initial_balance'] ?? '0.00'));

        return ToolOutcome::result(true, "اتعملت الخزينة {$treasury->name}.", [
            'treasury_id' => $treasury->id,
            'deep_link' => $this->presenter->deepLink('tr', $treasury->id),
        ]);
    }
}
