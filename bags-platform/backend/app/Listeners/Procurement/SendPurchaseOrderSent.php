<?php

declare(strict_types=1);

namespace App\Listeners\Procurement;

use App\Events\Procurement\PurchaseOrderSentToSupplier;
use App\Models\SupplierUser;
use App\Notifications\Procurement\PurchaseOrderSentNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Notifies active supplier portal users of a newly issued purchase order.
 */
class SendPurchaseOrderSent implements ShouldQueue
{
    /**
     * M8: never notify a supplier about a purchase order whose transaction
     * rolled back (the event is dispatched from inside the DB transaction).
     */
    public bool $afterCommit = true;

    public function handle(PurchaseOrderSentToSupplier $event): void
    {
        $users = SupplierUser::query()
            ->where('supplier_id', $event->purchaseOrder->supplier_id)
            ->where('is_active', true)
            ->get();

        if ($users->isNotEmpty()) {
            DB::transaction(fn () => Notification::sendNow($users, new PurchaseOrderSentNotification($event->purchaseOrder), ['database']));
        }
    }
}
