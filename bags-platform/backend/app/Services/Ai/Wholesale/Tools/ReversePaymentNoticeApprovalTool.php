<?php

declare(strict_types=1);

namespace App\Services\Ai\Wholesale\Tools;

use App\Enums\WholesalePaymentNoticeStatus;
use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Wholesale\WholesalePaymentNoticeService;

/**
 * Full financial reversal of an approved payment notice (distinct from the short undo window).
 */
final class ReversePaymentNoticeApprovalTool implements LocksTargets, WriteTool
{
    public function __construct(
        private ProposalBuilder $proposals,
        private WholesalePaymentNoticeService $notices,
    ) {}

    public function name(): string
    {
        return 'reverse_payment_notice_approval';
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
            'description' => 'يعكس اعتماد إشعار: حركة خزينة عكسية، إلغاء التخصيص، وإرجاع الطلبات غير المغطاة لغير مدفوعة.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'notice_id' => ['type' => 'INTEGER'],
                    'reason' => ['type' => 'STRING'],
                ],
                'required' => ['notice_id', 'reason'],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $notice = WholesalePaymentNotice::query()->find((int) ($args['notice_id'] ?? 0));
        $reason = trim((string) ($args['reason'] ?? ''));
        if ($notice === null || $notice->status !== WholesalePaymentNoticeStatus::Approved || $reason === '') {
            return ToolOutcome::denied('الإشعار لازم يكون معتمد ومعاه سبب العكس.', auditable: false);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['notice_id' => $notice->id, 'reason' => $reason],
            risk: 'high',
            title: 'عكس اعتماد '.$notice->notice_number,
            before: ['status' => 'approved', 'amount' => (string) $notice->amount],
            after: ['status' => 'reversed'],
            typedConfirmation: $notice->notice_number,
            typedConfirmationHint: 'اكتب رقم الإشعار',
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        WholesalePaymentNotice::query()->lockForUpdate()->findOrFail((int) $action->args['notice_id']);
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $notice = WholesalePaymentNotice::query()->findOrFail((int) $action->args['notice_id']);
        try {
            $reversed = $this->notices->reverse($notice, $actor->id, (string) $action->args['reason']);
        } catch (BusinessException $e) {
            return ToolOutcome::denied($e->getMessage(), auditable: false);
        }

        return ToolOutcome::result(true, 'اتعكس اعتماد '.$reversed->notice_number, ['notice_id' => $reversed->id]);
    }
}
