<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** تنبيه انخفاض مخزون متغير في فرع إلى حد إعادة الطلب. */
class LowStockAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $productVariantId,
        private readonly ?string $sku,
        private readonly int $branchId,
        private readonly float $available,
        private readonly float $reorderLevel,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'low_stock',
            'product_variant_id' => $this->productVariantId,
            'sku' => $this->sku,
            'branch_id' => $this->branchId,
            'available' => $this->available,
            'reorder_level' => $this->reorderLevel,
            'message' => "انخفض مخزون الصنف {$this->sku} في الفرع #{$this->branchId} إلى {$this->available} (حد إعادة الطلب {$this->reorderLevel}).",
        ];
    }
}
