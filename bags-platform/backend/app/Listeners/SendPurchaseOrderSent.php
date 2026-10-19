<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PurchaseOrderSentToSupplier;
use App\Models\SupplierUser;
use App\Notifications\PurchaseOrderSentNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/** إشعار مستخدمي المورد النشطين بأمر الشراء الجديد في بوابتهم. */
class SendPurchaseOrderSent implements ShouldQueue
{
    public function handle(PurchaseOrderSentToSupplier $event): void
    {
        $users = SupplierUser::query()
            ->where('supplier_id', $event->purchaseOrder->supplier_id)
            ->where('is_active', true)
            ->get();

        if ($users->isNotEmpty()) {
            Notification::send($users, new PurchaseOrderSentNotification($event->purchaseOrder));
        }
    }
}
