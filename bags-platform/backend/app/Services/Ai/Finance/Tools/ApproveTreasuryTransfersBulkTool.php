<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\Tools;

use App\Enums\Finance\TreasuryTransferStatus;
use App\Enums\Finance\TreasuryType;
use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Contracts\ExecutesSelectedItems;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\Finance\BulkUndoGuard;
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
use Illuminate\Support\Str;

final class ApproveTreasuryTransfersBulkTool implements AgentTool, ExecutesSelectedItems, LocksTargets, WriteTool
{
    public function __construct(
        private BranchAccess $branches,
        private FinancePresenter $presenter,
        private ProposalBuilder $proposals,
        private TreasuryService $treasury,
        private SettingService $settings,
    ) {}

    public function name(): string
    {
        return 'approve_treasury_transfers_bulk';
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
            'description' => 'اعتماد جماعي لتحويلات معلقة: المعاينة تعتمد على قابلية الاعتماد، والتنفيذ يتخطى الفاشل.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'transfer_ids' => ['type' => 'ARRAY', 'items' => ['type' => 'INTEGER']],
                    'treasury_id' => ['type' => 'INTEGER'],
                    'limit' => ['type' => 'INTEGER'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = FinanceArgs::normalizeDigitsInArray($args);
        try {
            $restricted = $this->branches->restrictedBranch($actor);
        } catch (\Throwable) {
            return ToolOutcome::denied('النطاق غير مسموح.', auditable: false);
        }
        $ids = array_values(array_unique(array_map('intval', (array) ($args['transfer_ids'] ?? []))));
        $maxItems = max(1, min(100, (int) $this->settings->get('ai_bulk_max_items', 25)));
        if (count($ids) > $maxItems) {
            return ToolOutcome::denied("الحد الأقصى {$maxItems} تحويل في الدفعة.", auditable: false);
        }
        $treasuryId = isset($args['treasury_id']) ? (int) $args['treasury_id'] : null;
        if ($ids === [] && $treasuryId === null) {
            return ToolOutcome::denied('حدد transfer_ids أو treasury_id.', auditable: false);
        }
        if ($treasuryId !== null) {
            $treasury = \App\Models\Treasury::find($treasuryId);
            if (! $treasury) {
                return ToolOutcome::denied('الخزينة مش موجودة.', auditable: false);
            }
            try {
                $this->branches->assertTreasury($actor->id, $treasury);
            } catch (\Illuminate\Auth\Access\AuthorizationException) {
                return ToolOutcome::denied('الخزينة خارج نطاقك.', auditable: false);
            }
            $ids = TreasuryTransfer::query()->where('status', TreasuryTransferStatus::Pending)
                ->where(fn ($q) => $q->where('from_treasury_id', $treasuryId)->orWhere('to_treasury_id', $treasuryId))
                ->orderBy('id')->limit(min($maxItems, max(1, (int) ($args['limit'] ?? 20))))->pluck('id')->all();
        }

        $approvable = [];
        $skipped = [];
        $expected = [];
        $selection = [];
        $items = [];
        foreach (TreasuryTransfer::query()->whereKey($ids)->with(['fromTreasury', 'toTreasury'])->orderBy('id')->get() as $transfer) {
            $status = $transfer->status instanceof \BackedEnum ? $transfer->status->value : (string) $transfer->status;
            $inScope = true;
            try {
                foreach ([$transfer->fromTreasury, $transfer->toTreasury] as $t) {
                    $this->branches->assertTreasury($actor->id, $t);
                }
            } catch (\Illuminate\Auth\Access\AuthorizationException) {
                $inScope = false;
            }
            $reason = match (true) {
                ! $inScope => 'خارج نطاقك',
                $status !== TreasuryTransferStatus::Pending->value => 'مش معلق',
                (int) $transfer->created_by === (int) $actor->id => 'أنت منشئه — الفصل إجباري',
                $transfer->fromTreasury->type !== TreasuryType::BankAccount
                    && bccomp($transfer->fromTreasury->current_balance, $transfer->amount, 2) < 0 => 'رصيد المصدر غير كافٍ',
                default => null,
            };
            $key = (string) Str::ulid();
            $items[] = [
                'key' => $key,
                'label' => $inScope ? $transfer->transfer_number : '#'.$transfer->id,
                'change' => $inScope ? $transfer->fromTreasury->name.' ← '.$transfer->toTreasury->name.' · '.$this->presenter->money($transfer->amount) : '',
                'ok' => $reason === null,
                'message' => $reason,
            ];
            if ($reason === null) {
                $approvable[] = $transfer->id;
                $selection[$key] = $transfer->id;
                $expected[ApproveTreasuryTransferTool::versionKey($transfer)] = FinanceWriteGuards::transferVersion($transfer);
            } else {
                $skipped[] = ['transfer_id' => $transfer->id, 'reason' => $reason];
            }
        }
        if ($approvable === []) {
            return ToolOutcome::denied('مفيش تحويلات قابلة للاعتماد في الاختيار ده.', auditable: false);
        }
        $total = (float) TreasuryTransfer::query()->whereKey($approvable)->sum('amount');
        $guard = new BulkUndoGuard;
        $threshold = (float) $this->settings->get('ai_finance_high_risk_amount', 20000);
        $risk = $total > $threshold ? 'high' : $this->risk();

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['transfer_ids' => $approvable, 'skipped' => $skipped, 'selection' => $selection],
            risk: $risk,
            title: 'اعتماد جماعي لـ '.count($approvable).' تحويلات',
            before: ['pending_count' => count($approvable)],
            after: ['approved_count' => count($approvable), 'total' => $total],
            warnings: [$guard->reason()],
            expectedVersions: $expected,
            typedConfirmation: $risk === 'high' ? 'تنفيذ' : null,
            extraPreview: ['block_type' => 'bulk_plan', 'items' => $items],
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        (new FinanceLocks)->lockTransfers(array_map('intval', (array) ($action->args['transfer_ids'] ?? [])), $action->user_id);
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        return $this->approve($actor, $action, $action->args['transfer_ids'] ?? []);
    }

    public function executeSelected(User $actor, AiPendingAction $action, array $selectedItems): ToolOutcome
    {
        $selection = $action->args['selection'] ?? [];
        abort_if($selectedItems === [] || array_diff($selectedItems, array_keys($selection)) !== [], 422, 'الاختيار فيه تحويل غير متاح.');

        return $this->approve($actor, $action, array_values(array_intersect_key($selection, array_flip($selectedItems))));
    }

    private function approve(User $actor, AiPendingAction $action, array $ids): ToolOutcome
    {
        $items = [];
        $succeeded = 0;
        foreach ($ids as $id) {
            $transfer = TreasuryTransfer::query()->find($id);
            if (! $transfer) {
                $items[] = ['transfer_id' => $id, 'ok' => false, 'message' => 'التحويل لم يعد موجودًا.'];

                continue;
            }
            $expected = $action->expected_versions[ApproveTreasuryTransferTool::versionKey($transfer)] ?? null;
            if ($expected !== FinanceWriteGuards::transferVersion($transfer)) {
                $items[] = ['transfer_id' => $id, 'ok' => false, 'message' => 'التحويل اتغير بعد الاقتراح.'];

                continue;
            }
            try {
                foreach ([$transfer->fromTreasury, $transfer->toTreasury] as $t) {
                    $this->branches->assertTreasury($actor->id, $t);
                }
                $updated = $this->treasury->approveTransfer($transfer, $actor->id);
                $succeeded++;
                $items[] = ['transfer_id' => $id, 'ok' => true, 'message' => "اتعتمد {$updated->transfer_number}."];
            } catch (BusinessException $e) {
                $items[] = ['transfer_id' => $id, 'ok' => false, 'message' => $e->getMessage()];
            }
        }
        $ok = $succeeded === count($ids) && $ids !== [];
        $message = "اتنفذ {$succeeded} من ".count($ids).' تحويلات.';

        return ToolOutcome::result($ok, $message, [
            'items' => $items,
            'deep_link' => $this->presenter->deepLink('trf'),
        ]);
    }
}
