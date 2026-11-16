<?php

declare(strict_types=1);

namespace App\Console\Commands\Wholesale;

use App\Enums\Orders\OrderStatus;
use App\Events\Orders\OrderBecameOverdue;
use App\Models\Order;
use App\Models\OutboundMessage;
use Illuminate\Console\Command;

/**
 * Detects unpaid wholesale credit orders past their due date and triggers overdue notifications.
 */
class MarkWholesaleOverdue extends Command
{
    protected $signature = 'wholesale:mark-overdue';

    protected $description = 'Dispatch OrderBecameOverdue once for each unpaid credit order past due_at';

    public function handle(): int
    {
        $marked = 0;
        Order::query()
            ->where('payment_method', 'credit')
            ->where('payment_status', 'unpaid')
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now((string) config('app.business_timezone', 'Africa/Cairo'))->startOfDay())
            ->chunkById(200, function ($orders) use (&$marked): void {
                foreach ($orders as $order) {
                    $due = $order->due_at?->toDateString() ?? '';
                    $key = "order-overdue:{$order->id}:{$due}";
                    if (OutboundMessage::query()->where('idempotency_key', $key)->exists()) {
                        continue;
                    }
                    OrderBecameOverdue::dispatch($order);
                    $marked++;
                }
            });

        $this->info("Marked {$marked} overdue orders.");

        return self::SUCCESS;
    }
}
