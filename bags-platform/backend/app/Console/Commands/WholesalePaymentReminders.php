<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Services\Shared\SettingService;
use App\Services\Wholesale\WholesaleOutbox;
use Illuminate\Console\Command;

/**
 * Enqueues upcoming and recent credit payment reminders, respecting customer contact hours and weekly frequency limits.
 */
class WholesalePaymentReminders extends Command
{
    protected $signature = 'wholesale:payment-reminders';

    protected $description = 'Queue due-date reminders inside contact hours and a weekly cap';

    public function handle(SettingService $settings, WholesaleOutbox $outbox): int
    {
        $cap = max(1, (int) $settings->get('ai_wholesale_reminder_weekly_cap', 3));
        $orders = Order::query()
            ->with('customer')
            ->where('payment_method', 'credit')
            ->where('payment_status', 'unpaid')
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [now()->subDays(7)->startOfDay(), now()->addDays(3)->endOfDay()])
            ->limit(200)
            ->get();
        foreach ($orders as $order) {
            $customer = $order->customer;
            if ($customer === null) {
                continue;
            }
            $sent = OutboundMessage::query()
                ->where('customer_id', $customer->id)
                ->where('template', 'payment_reminder')
                ->where('created_at', '>=', now()->subWeek())
                ->count();
            if ($sent >= $cap) {
                continue;
            }
            $due = $order->due_at?->toDateString() ?? '';
            $outbox->queue(
                "payment-reminder:{$order->id}:{$due}",
                'customer_whatsapp',
                $customer->id,
                'payment_reminder',
                ['order_number' => $order->order_number, 'due_at' => $due],
                $outbox->nextContactAt($customer),
                $outbox->customerAllowsWhatsapp($customer),
            );
        }

        return self::SUCCESS;
    }
}
