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
use App\Services\Shared\SettingService;
use App\Services\Wholesale\WholesalePaymentNoticeService;

final class ApprovePaymentNoticeTool implements LocksTargets, WriteTool
{
    public function __construct(
        private ProposalBuilder $proposals,
        private SettingService $settings,
        private WholesalePaymentNoticeService $notices,
    ) {}

    public function name(): string
    {
        return 'approve_payment_notice';
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
            'description' => 'يعتمد إشعار سداد. التأكيد بكتابة المبلغ رقمين عشريين. على واتساب لازم المطابقة 95 والتأكيد والمسموح الصريح.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'notice_id' => ['type' => 'INTEGER'],
                    'treasury_id' => ['type' => 'INTEGER'],
                ],
                'required' => ['notice_id'],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $notice = WholesalePaymentNotice::query()->with('customer')->find((int) ($args['notice_id'] ?? 0));
        if ($notice === null || $notice->status !== WholesalePaymentNoticeStatus::PendingReview) {
            return ToolOutcome::denied('إشعار السداد مش موجود أو اتعمله مراجعة.', auditable: false);
        }
        $amount = number_format((float) $notice->amount, 2, '.', '');
        $collisions = $this->notices->receiptCollisions($notice);
        $allocation = $this->notices->previewNoticeAllocation($notice);
        $score = $notice->match_score === null ? null : (float) $notice->match_score;
        if ($this->proposals->conversation()?->channel === 'whatsapp') {
            $allowed = $this->settings->getBool('ai_whatsapp_allow_notice_approval', false)
                && $this->settings->getBool('ai_whatsapp_pin_required', false);
            if (! $allowed || $score === null || $score < 95) {
                return ToolOutcome::denied('اعتماد الإشعار من واتساب متاح فقط بمطابقة 95% والتأكيد الصريح.', auditable: false);
            }
        }
        $warnings = $collisions === [] ? [] : ['نفس الإيصال ظهر على حساب تاني.'];

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['notice_id' => $notice->id, 'treasury_id' => $args['treasury_id'] ?? null],
            risk: 'high',
            title: 'اعتماد '.$notice->notice_number,
            before: ['status' => 'pending_review', 'match_score' => $score],
            after: ['status' => 'approved', 'allocation' => $allocation],
            warnings: $warnings,
            expectedVersions: ['n:'.$notice->id => (string) $notice->updated_at],
            typedConfirmation: $amount,
            typedConfirmationHint: 'اكتب المبلغ بالأرقام',
            extraPreview: [
                'allocation' => $allocation,
                'collisions' => $collisions,
                'treasury_id' => $args['treasury_id'] ?? null,
            ],
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        WholesalePaymentNotice::query()->lockForUpdate()->findOrFail((int) $action->args['notice_id']);
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $notice = WholesalePaymentNotice::query()->findOrFail((int) $action->args['notice_id']);
        if (($action->expected_versions['n:'.$notice->id] ?? null) !== (string) $notice->updated_at) {
            return ToolOutcome::denied('الإشعار اتغير بعد الاقتراح.', auditable: false);
        }
        if ($this->proposals->conversation()?->channel === 'whatsapp' && (float) $notice->match_score < 95) {
            return ToolOutcome::denied('مطابقة الإيصال أقل من 95.', auditable: false);
        }
        try {
            $approved = $this->notices->approve(
                $notice,
                $actor->id,
                isset($action->args['treasury_id']) ? (int) $action->args['treasury_id'] : null,
            );
        } catch (BusinessException $e) {
            return ToolOutcome::denied($e->getMessage(), auditable: false);
        }

        return ToolOutcome::result(true, 'اتعمد '.$approved->notice_number, ['notice_id' => $approved->id]);
    }
}
