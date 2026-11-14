<?php

declare(strict_types=1);

namespace App\Notifications\Inventory;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Alerts staff when branch variant inventory drops to or below the reorder point.
 */
class LowStockAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private ?int $locationId = null;

    public function __construct(
        private readonly int $productVariantId,
        private readonly ?string $sku,
        private readonly int $branchId,
        private readonly float $available,
        private readonly float $reorderLevel,
        ?int $locationId = null,
    ) {
        $this->locationId = $locationId;
    }

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
            'location_id' => $this->locationId,
            'available' => $this->available,
            'reorder_level' => $this->reorderLevel,
            'message' => "انخفض مخزون الصنف {$this->sku} في الفرع #{$this->branchId} إلى {$this->available} (حد إعادة الطلب {$this->reorderLevel}).",
        ];
    }
}
