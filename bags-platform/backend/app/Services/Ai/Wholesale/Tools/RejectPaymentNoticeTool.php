<?php

declare(strict_types=1);

namespace App\Services\Ai\Wholesale\Tools;

use App\Enums\WholesalePaymentNoticeStatus;
use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Wholesale\WholesalePaymentNoticeService;

final class RejectPaymentNoticeTool implements WriteTool
{
    /** @var list<string> */
    private const REASONS = ['المبلغ غير مطابق', 'الإيصال غير واضح', 'التحويل لم يصل', 'إيصال مكرر'];

    public function __construct(
        private ProposalBuilder $proposals,
        private WholesalePaymentNoticeService $notices,
    ) {}

    public function name(): string
    {
        return 'reject_payment_notice';
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
        return ['payment_notices.approve'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'يرفض إشعار سداد بسبب من القائمة، والسبب يوصل للتاجر.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'notice_id' => ['type' => 'INTEGER'],
                    'reason' => ['type' => 'STRING'],
                ],
                'required' => ['notice_id'],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $notice = WholesalePaymentNotice::query()->find((int) ($args['notice_id'] ?? 0));
        if ($notice === null || $notice->status !== WholesalePaymentNoticeStatus::PendingReview) {
            return ToolOutcome::denied('إشعار السداد مش موجود أو اتعمله مراجعة.', auditable: false);
        }
        $reason = trim((string) ($args['reason'] ?? ''));
        if (! in_array($reason, self::REASONS, true)) {
            return ToolOutcome::picker([
                'type' => 'reason_picker',
                'field' => 'reason',
                'purpose' => 'سبب الرفض',
                'options' => array_map(fn ($reason) => ['id' => $reason, 'label' => $reason], self::REASONS),
            ]);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['notice_id' => $notice->id, 'reason' => $reason],
            title: 'رفض '.$notice->notice_number,
            after: ['reason' => $reason],
            expectedVersions: ['n:'.$notice->id => (string) $notice->updated_at],
        );
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $notice = WholesalePaymentNotice::query()->findOrFail((int) $action->args['notice_id']);
        if (($action->expected_versions['n:'.$notice->id] ?? null) !== (string) $notice->updated_at) {
            return ToolOutcome::denied('الإشعار اتغير بعد الاقتراح.', auditable: false);
        }
        try {
            $this->notices->reject($notice, $actor->id, (string) $action->args['reason']);
        } catch (BusinessException $e) {
            return ToolOutcome::denied($e->getMessage(), auditable: false);
        }

        return ToolOutcome::result(true, 'اترفض الإشعار والسبب اتسجل للتاجر.');
    }
}
