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

/** تنبيه مديري المشتريات (purchases.view) ومديري الفرع بانخفاض المخزون. */
class SendLowStockAlert implements ShouldQueue
{
    public function handle(StockLowDetected $event): void
    {
        // L4: balance oscillation around the threshold (sale → return → sale)
        // re-crosses on every pass, and row-level + variant-level checks can
        // fire two events for one movement — one alert per variant/branch
        // every 6 hours is enough for a restock signal.
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
