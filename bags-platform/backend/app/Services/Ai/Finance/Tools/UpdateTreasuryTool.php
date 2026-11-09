<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\Tools;

use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\Treasury;
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
use App\Services\Pos\TreasuryService;
use Illuminate\Auth\Access\AuthorizationException;

final class UpdateTreasuryTool implements AgentTool, LocksTargets, UndoableTool, WriteTool
{
    public function __construct(
        private EntityResolver $resolver,
        private BranchAccess $branches,
        private FinanceWriteGuards $guards,
        private FinancePresenter $presenter,
        private ProposalBuilder $proposals,
        private TreasuryService $treasuryService,
    ) {}

    public function name(): string
    {
        return 'update_treasury';
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
        return ['treasuries.update'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'تعديل اسم الخزينة أو كودها أو الفرع. لتغيير الحالة استخدم set_treasury_status.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'treasury_id' => ['type' => 'INTEGER'],
                    'treasury_query' => ['type' => 'STRING'],
                    'name' => ['type' => 'STRING'],
                    'code' => ['type' => 'STRING'],
                    'branch_id' => ['type' => 'INTEGER', 'nullable' => true],
                    'type' => ['type' => 'STRING'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = FinanceArgs::normalizeDigitsInArray($args);
        $resolved = FinanceArgs::resolveTreasury($actor, $this->resolver, $this->branches, $args);
        if ($resolved instanceof ToolOutcome) {
            return $resolved;
        }
        $treasury = $resolved['treasury'];
        $currentType = $treasury->type instanceof \BackedEnum ? $treasury->type->value : (string) $treasury->type;
        if (isset($args['type']) && (string) $args['type'] !== $currentType) {
            return ToolOutcome::denied('نوع الخزينة ثابت بعد الإنشاء ولا يمكن تغييره.', auditable: true);
        }
        $patch = [];
        if (isset($args['name'])) {
            $name = trim((string) $args['name']);
            if ($name === '' || mb_strlen($name) > 191) {
                return ToolOutcome::denied('اسم الخزينة مطلوب (حتى 191 حرفًا).', auditable: false);
            }
            $patch['name'] = $name;
        }
        if (array_key_exists('is_active', $args)) {
            return ToolOutcome::denied('لتغيير حالة الخزينة استخدم set_treasury_status.', auditable: false);
        }
        if (isset($args['code'])) {
            $code = trim((string) $args['code']);
            if ($code === '' || mb_strlen($code) > 50 || Treasury::query()->whereKeyNot($treasury->id)->where('code', $code)->exists()) {
                return ToolOutcome::denied('حدد كود خزينة فريدًا في حدود ٥٠ حرفًا.', auditable: false);
            }
            $patch['code'] = $code;
        }
        if (array_key_exists('branch_id', $args)) {
            try {
                $patch['branch_id'] = $this->guards->resolveBranch($actor, $args['branch_id'] === null ? null : (int) $args['branch_id']);
            } catch (AuthorizationException) {
                return ToolOutcome::denied('الفرع خارج نطاقك.', auditable: false);
            }
            if ($patch['branch_id'] != $treasury->branch_id && $this->guards->deactivationBlockers($treasury) !== []) {
                return ToolOutcome::denied('لا يمكن نقل خزينة لها رصيد أو حركات أو عمليات معلقة إلى فرع آخر.', auditable: false);
            }
        }
        if ($patch === []) {
            return ToolOutcome::denied('مفيش تغيير.', auditable: false);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['treasury_id' => $treasury->id] + $patch,
            risk: $this->risk(),
            title: "تعديل خزينة {$treasury->name}",
            before: array_intersect_key($treasury->getAttributes(), $patch),
            after: $patch,
            expectedVersions: $this->guards->accountVersions($treasury),
            inverse: array_intersect_key($treasury->getAttributes(), $patch),
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        (new FinanceLocks)->lockTargets($action);
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $a = $action->args;
        $treasury = Treasury::query()->lockForUpdate()->findOrFail((int) $a['treasury_id']);
        $this->branches->assertTreasury($actor->id, $treasury);
        if (array_diff_assoc($this->guards->accountVersions($treasury), $action->expected_versions ?? []) !== []) {
            throw new BusinessException('الخزينة اتعدلت بعد الاقتراح. اطلب اقتراحًا جديدًا.', [], 409);
        }
        if (array_key_exists('is_active', $a)) {
            throw new BusinessException('البيانات اتغيرت بعد الاقتراح. اطلب اقتراحًا جديدًا.', [], 409);
        }
        $treasury = $this->treasuryService->updateAccount($treasury, array_intersect_key($a, ['name' => true, 'code' => true, 'branch_id' => true]), $actor);

        return ToolOutcome::result(true, "اتعدلت الخزينة {$treasury->name}.", [
            'treasury_id' => $treasury->id,
            'deep_link' => $this->presenter->deepLink('tr', $treasury->id),
        ]);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        return is_array($action->inverse) && Treasury::query()->whereKey($action->args['treasury_id'])->exists();
    }

    public function targetState(AiPendingAction $action): string
    {
        return hash('sha256', json_encode($this->guards->accountVersions(Treasury::findOrFail($action->args['treasury_id'])), JSON_THROW_ON_ERROR));
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        $treasury = Treasury::findOrFail($action->args['treasury_id']);
        $previous = $action->inverse;
        if (isset($previous['code']) && Treasury::query()->whereKeyNot($treasury->id)->where('code', $previous['code'])->exists()) {
            throw new BusinessException('الكود السابق مستخدم بالفعل.', [], 409);
        }
        $this->treasuryService->updateAccount($treasury, $previous, $actor);

        return ToolOutcome::result(true, 'اترجعت بيانات الخزينة السابقة.');
    }
}
