<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\StockLowDetected;
use App\Models\ProductVariant;
use App\Notifications\LowStockAlertNotification;
use App\Notifications\StaffRecipients;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/** تنبيه مديري المشتريات (purchases.view) ومديري الفرع بانخفاض المخزون. */
class SendLowStockAlert implements ShouldQueue
{
    public function handle(StockLowDetected $event): void
    {
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
