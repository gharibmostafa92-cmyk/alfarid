<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\StockLowDetected;
use App\Models\ProductVariant;
use App\Notifications\LowStockAlertNotification;
use App\Notifications\StaffRecipients;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Notifies purchasing managers and branch managers when inventory drops below the reorder threshold.
 */
class SendLowStockAlert implements ShouldQueue
{
    /**
     * Handle the low stock event with cooldown debouncing.
     */
    public function handle(StockLowDetected $event): void
    {
        // Debounce alerts to once per 6 hours per variant/branch to prevent notification fatigue from threshold oscillations.
        if (! Cache::add("low-stock:{$event->productVariantId}:{$event->branchId}", 1, now()->addHours(6))) {
            return;
        }

        $recipients = StaffRecipients::withAnyPermission(['purchases.view'], [$event->branchId])
            ->merge(StaffRecipients::branchManagers($event->branchId))
            ->unique('id');

        if ($recipients->isEmpty()) {
            return;
        }

        $sku = ProductVariant::query()->whereKey($event->productVariantId)->value('sku');

        DB::transaction(fn () => Notification::sendNow($recipients, new LowStockAlertNotification(
            $event->productVariantId,
            $sku !== null ? (string) $sku : null,
            $event->branchId,
            $event->available,
            $event->reorderLevel,
        ), ['database']));
    }
}
