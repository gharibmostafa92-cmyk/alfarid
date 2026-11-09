<?php

declare(strict_types=1);

namespace App\Services\Ai\Wholesale\Tools;

use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\Ai\AgentTool;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Wholesale\WholesalePaymentNoticeService;

final class GetPaymentNoticeTool implements AgentTool
{
    public function __construct(private WholesalePaymentNoticeService $notices) {}

    public function name(): string
    {
        return 'get_payment_notice';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function risk(): string
    {
        return 'low';
    }

    public function permissions(): array
    {
        return ['payment_notices.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'إشعار سداد، رابط الإيصال، والطلبات اللي هتتأثر لو اتعمد، مع تحذير لو الإيصال متكرر.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) ['notice_id' => ['type' => 'INTEGER']],
                'required' => ['notice_id'],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $notice = WholesalePaymentNotice::query()->with(['customer', 'order'])->find((int) ($args['notice_id'] ?? 0));
        if ($notice === null) {
            return ToolOutcome::denied('إشعار السداد مش موجود.', auditable: false);
        }

        $collisions = $this->notices->receiptCollisions($notice);
        $preview = $this->notices->previewNoticeAllocation($notice);
        $warnings = array_map(
            fn (array $hit) => "نفس الإيصال اترفع قبل كده من حساب تاني ({$hit['notice_number']}).",
            $collisions,
        );
        $ocr = is_array($notice->ocr_payload) ? $notice->ocr_payload : [];

        return ToolOutcome::data([
            'id' => $notice->id,
            'number' => $notice->notice_number,
            'status' => $notice->status->value,
            'amount' => (string) $notice->amount,
            'bank_name' => $notice->bank_name,
            'reference_number' => $notice->reference_number,
            'transfer_date' => $notice->transfer_date?->toDateString(),
            'approved_at' => $notice->approved_at?->toIso8601String(),
            'match_score' => $notice->match_score !== null ? (float) $notice->match_score : null,
            'customer' => $notice->customer?->company_name ?: $notice->customer?->name,
            'receipt_url' => route('payment-notices.receipt', ['notice' => $notice->id]),
            'ocr' => $ocr === [] ? null : ToolOutcome::untrusted(json_encode($ocr, JSON_UNESCAPED_UNICODE) ?: ''),
            'collisions' => $collisions,
            'warnings' => $warnings,
            'allocation_preview' => $preview,
        ], [
            'type' => 'notice_card',
            'id' => $notice->id,
            'number' => $notice->notice_number,
            'amount' => (string) $notice->amount,
            'status' => $notice->status->value,
            'warnings' => $warnings,
            'receipt_url' => route('payment-notices.receipt', ['notice' => $notice->id]),
        ]);
    }
}
