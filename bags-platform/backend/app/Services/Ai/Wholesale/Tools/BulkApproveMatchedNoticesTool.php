<?php

declare(strict_types=1);

namespace App\Services\Ai\Wholesale\Tools;

use App\Enums\WholesalePaymentNoticeStatus;
use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\Ai\Contracts\ExecutesSelectedItems;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\WholesalePaymentNoticeService;

final class BulkApproveMatchedNoticesTool implements ExecutesSelectedItems, WriteTool
{
    public function __construct(
        private ProposalBuilder $proposals,
        private WholesalePaymentNoticeService $notices,
    ) {}

    public function name(): string
    {
        return 'bulk_approve_matched_notices';
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
        return ['payment_notices.approve'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'يقترح اعتماد الإشعارات المطابقة 95% من غير تحذيرات. الموظف يختار.',
            'parameters' => ['type' => 'OBJECT', 'properties' => (object) []],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $rows = WholesalePaymentNotice::query()
            ->where('status', WholesalePaymentNoticeStatus::PendingReview)
            ->where('match_score', '>=', 95)
            ->limit(50)
            ->get();
        $items = [];
        $ids = [];
        $expected = [];
        foreach ($rows as $notice) {
            if ($this->notices->receiptCollisions($notice) !== []) {
                continue;
            }
            $key = 'n'.$notice->id;
            $items[] = ['key' => $key, 'label' => $notice->notice_number, 'detail' => (string) $notice->amount];
            $ids[$key] = $notice->id;
            $expected['n:'.$notice->id] = (string) $notice->updated_at;
        }
        if ($ids === []) {
            return ToolOutcome::denied('مفيش إشعارات مطابقة وجاهزة.', auditable: false);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['notices' => $ids],
            risk: 'high',
            title: 'اعتماد '.count($ids).' إشعارات مطابقة',
            expectedVersions: $expected,
            typedConfirmation: 'تنفيذ',
            extraPreview: ['block_type' => 'bulk_plan', 'items' => $items],
        );
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        return $this->approve($actor, $action, array_keys($action->args['notices'] ?? []));
    }

    public function executeSelected(User $actor, AiPendingAction $action, array $selectedItems): ToolOutcome
    {
        $notices = $action->args['notices'] ?? [];
        if ($selectedItems === [] || array_diff($selectedItems, array_keys($notices)) !== []) {
            return ToolOutcome::denied('الاختيار فيه إشعار غير متاح.', auditable: false);
        }

        return $this->approve($actor, $action, $selectedItems);
    }

    /** @param  list<string>  $keys */
    private function approve(User $actor, AiPendingAction $action, array $keys): ToolOutcome
    {
        $done = 0;
        foreach ($keys as $key) {
            $id = (int) ($action->args['notices'][$key] ?? 0);
            $notice = WholesalePaymentNotice::query()->find($id);
            if ($notice === null || (float) $notice->match_score < 95) {
                continue;
            }
            if (($action->expected_versions['n:'.$notice->id] ?? null) !== (string) $notice->updated_at) {
                continue;
            }
            if ($this->notices->receiptCollisions($notice) !== []) {
                continue;
            }
            try {
                $this->notices->approve($notice, $actor->id);
                $done++;
            } catch (BusinessException) {
                continue;
            }
        }

        return ToolOutcome::result($done === count($keys) && $keys !== [], "اتعمد {$done} من ".count($keys).' إشعارات.');
    }
}
