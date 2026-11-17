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
        $branches = $event->order->items()->whereNotNull('allocated_branch_id')->pluck('allocated_branch_id');
        if ($event->order->allocated_branch_id !== null) {
            $branches->push($event->order->allocated_branch_id);
        }
        // Orders without any allocation stay central-only by policy: pass the
        // empty scope through so only Super Admins and branch-less (central)
        // staff are notified. Passing null here would fan unroutable orders
        // out to every branch team, which is a behavior change, not a fix.
        $branchIds = $branches->map(fn ($id) => (int) $id)->unique()->values()->all();
        $staff = StaffRecipients::withAnyPermission(['orders.view'], $branchIds);

        if ($staff->isNotEmpty()) {
            DB::transaction(fn () => Notification::sendNow($staff, new AdminOrderCreatedNotification($event->order), ['database']));
        }
    }
}
