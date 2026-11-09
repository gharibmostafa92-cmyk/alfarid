<?php

declare(strict_types=1);

namespace App\Listeners\Orders;

use App\Events\Orders\OrderCreated;
use App\Notifications\Orders\AdminOrderCreatedNotification;
use App\Notifications\StaffRecipients;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Staff notification is decoupled from SendOrderConfirmation.
 *
 * Two separate queued listeners (auto-discovered via OrderCreated type-hints)
 * so a staff-notify failure retries only the staff fan-out — the customer's
 * confirmation is never duplicated by a staff-side retry.
 */
class SendStaffOrderNotification implements ShouldQueue
{
    public function handle(OrderCreated $event): void
    {
        $branch = $event->order->allocated_branch_id;
        $staff = StaffRecipients::withAnyPermission(['orders.view'], $branch === null ? [] : [(int) $branch]);

        if ($staff->isNotEmpty()) {
            DB::transaction(fn () => Notification::sendNow($staff, new AdminOrderCreatedNotification($event->order), ['database']));
        }
    }
}
