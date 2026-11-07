<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Proactive notification suggesting stock transfer from a surplus branch to a low-stock branch.
 */
class TransferSuggestionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $productVariantId,
        private readonly ?string $sku,
        private readonly int $fromBranchId,
        private readonly int $toBranchId,
        private readonly float $quantity,
        private readonly ?float $lowCoverageDays,
        private readonly ?float $donorCoverageDays,
        private readonly string $aiPrompt,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $low = $this->lowCoverageDays !== null ? "فاضل له {$this->lowCoverageDays} يوم" : 'رصيده منخفض';
        $donor = $this->donorCoverageDays !== null ? "تغطية {$this->donorCoverageDays} يوم" : 'بلا مبيعات';

        return [
            'kind' => 'transfer_suggestion',
            'product_variant_id' => $this->productVariantId,
            'sku' => $this->sku,
            'from_branch_id' => $this->fromBranchId,
            'to_branch_id' => $this->toBranchId,
            'quantity' => $this->quantity,
            'low_coverage_days' => $this->lowCoverageDays,
            'donor_coverage_days' => $this->donorCoverageDays,
            'ai_prompt' => $this->aiPrompt,
            'message' => "فرع #{$this->toBranchId} {$low} من الصنف {$this->sku}، وفرع #{$this->fromBranchId} عنده ({$donor}). مقترح: تحويل {$this->quantity} قطعة.",
        ];
    }
}
