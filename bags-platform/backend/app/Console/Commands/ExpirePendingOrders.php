<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Console\Command;
use Throwable;

/**
 * إلغاء الطلبات المعلقة منتهية المهلة (F-01): الحجز صار فوريًا عند الشراء،
 * فالطلب المهجور يحبس مخزونًا حتى يُلغى ويُفك حجزه من هنا.
 * إلكتروني غير مدفوع: دقائق (إعداد) — COD: ساعات يحددها النشاط (إعداد).
 */
class ExpirePendingOrders extends Command
{
    protected $signature = 'orders:expire-pending';

    protected $description = 'Cancel stale pending orders and release their stock reservations';

    public function handle(OrderService $orders): int
    {
        $onlineBefore = now()->subMinutes(max(1, (int) config('orders.pending_online_expire_minutes', 30)));
        $codBefore = now()->subHours(max(1, (int) config('orders.pending_cod_expire_hours', 72)));
        // طلب الزائر (بلا حساب) أسرع انتهاءً: لا يحبس المخزون طويلًا بلا التزام (F5)
        $guestCodBefore = now()->subHours(max(1, (int) config('orders.pending_guest_cod_expire_hours', 24)));

        $stale = Order::query()
            ->where('status', OrderStatus::Pending)
            // «credit» (الدفع على الحساب) ليس بوابة تنتظر سدادًا: لا يُلغى بمهلة الدفع (F6)
            ->whereNotIn('payment_method', ['credit'])
            ->where(fn ($q) => $q
                ->where(fn ($online) => $online
                    ->where('payment_method', '!=', 'cod')
                    ->where('payment_status', 'unpaid')
                    ->where('created_at', '<', $onlineBefore))
                ->orWhere(fn ($cod) => $cod
                    ->where('payment_method', 'cod')
                    ->whereNull('customer_id')
                    ->where('created_at', '<', $guestCodBefore))
                ->orWhere(fn ($cod) => $cod
                    ->where('payment_method', 'cod')
                    ->whereNotNull('customer_id')
                    ->where('created_at', '<', $codBefore)))
            ->orderBy('id')
            ->get();

        $cancelled = 0;
        foreach ($stale as $order) {
            try {
                $orders->changeStatus($order, OrderStatus::Cancelled, null, 'إلغاء تلقائي: انتهت مهلة انتظار الطلب');
                $cancelled++;
            } catch (Throwable $e) {
                $this->warn("Order {$order->order_number}: {$e->getMessage()}");
            }
        }

        $this->info("Expired {$cancelled} pending orders.");

        return self::SUCCESS;
    }
}
