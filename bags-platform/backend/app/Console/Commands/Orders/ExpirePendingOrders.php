<?php

declare(strict_types=1);

namespace App\Console\Commands\Orders;

use App\Enums\Orders\OrderStatus;
use App\Enums\Wholesale\WholesalePaymentNoticeStatus;
use App\Models\Order;
use App\Services\Orders\OrderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cancels expired pending orders and releases their held inventory reservations.
 *
 * Stock is reserved immediately upon checkout, so abandoned orders must be
 * cancelled promptly to prevent indefinite stock locking across channels.
 */
class ExpirePendingOrders extends Command
{
    protected $signature = 'orders:expire-pending';

    protected $description = 'Cancel stale pending orders and release their stock reservations';

    /**
     * Scan and cancel stale pending orders across payment methods.
     *
     * @param  OrderService  $orders  Handles order state transitions and stock reservation release.
     * @return int Command exit code (0 for success).
     */
    public function handle(OrderService $orders): int
    {
        $onlineBefore = now()->subMinutes(max(1, (int) config('orders.pending_online_expire_minutes', 90)));
        $bankTransferBefore = now()->subHours(max(1, (int) config('orders.pending_bank_transfer_expire_hours', 48)));
        $codBefore = now()->subHours(max(1, (int) config('orders.pending_cod_expire_hours', 72)));
        // Guest COD orders have shorter TTLs to avoid stock locking by uncommitted buyers.
        $guestCodBefore = now()->subHours(max(1, (int) config('orders.pending_guest_cod_expire_hours', 12)));

        $stale = Order::query()
            ->where('status', OrderStatus::Pending)
            // On-account (credit) terms are pre-approved deferred debt, not awaiting gateway payment.
            ->whereNotIn('payment_method', ['credit'])
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('wholesale_payment_notices')
                    ->whereColumn('wholesale_payment_notices.order_id', 'orders.id')
                    ->where('wholesale_payment_notices.status', WholesalePaymentNoticeStatus::PendingReview->value);
            })
            ->where(fn ($q) => $q
                ->where(fn ($online) => $online
                    ->whereNotIn('payment_method', ['cod', 'bank_transfer'])
                    ->where('payment_status', 'unpaid')
                    ->where('created_at', '<', $onlineBefore))
                ->orWhere(fn ($bank) => $bank
                    ->where('payment_method', 'bank_transfer')
                    ->where('payment_status', 'unpaid')
                    ->where('created_at', '<', $bankTransferBefore))
                ->orWhere(fn ($cod) => $cod
                    ->where('payment_method', 'cod')
                    ->whereNull('customer_id')
                    ->where('created_at', '<', $guestCodBefore))
                ->orWhere(fn ($cod) => $cod
                    ->where('payment_method', 'cod')
                    ->whereNotNull('customer_id')
                    ->where('created_at', '<', $codBefore)))
            ->lazyById(500);

        $cancelled = 0;
        foreach ($stale as $order) {
            // Isolate individual order errors so a single failure does not halt the batch.
            try {
                $orders->changeStatus(
                    $order,
                    OrderStatus::Cancelled,
                    null,
                    'إلغاء تلقائي: انتهت مهلة انتظار الطلب',
                    [OrderStatus::Pending],
                );
                $cancelled++;
            } catch (Throwable $e) {
                $this->warn("Order {$order->order_number}: {$e->getMessage()}");
            }
        }

        $this->info("Expired {$cancelled} pending orders.");

        return self::SUCCESS;
    }
}
