<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * خدمات الطلب. تغيير الحالة يمر من هنا حصرًا: يُحدّث الطلب، ويسجل
 * التغيير في order_status_histories بنفس الـ transaction، وعند الانتقال
 * إلى confirmed يُطلق الحجز والتوزيع على الفروع تلقائيًا.
 */
class OrderService
{
    public function __construct(protected OrderAllocationService $allocationService) {}

    public function changeStatus(Order $order, OrderStatus|string $toStatus, ?Model $actor = null, ?string $notes = null): Order
    {
        $to = $toStatus instanceof OrderStatus ? $toStatus : OrderStatus::from($toStatus);

        return DB::transaction(function () use ($order, $to, $actor, $notes): Order {
            $from = $order->status;
            if ($from === $to) {
                return $order;
            }

            $order->update(['status' => $to]);

            $order->statusHistories()->create([
                'from_status' => $from,
                'to_status' => $to,
                'changed_by_type' => $actor?->getMorphClass(),
                'changed_by_id' => $actor?->getKey(),
                'notes' => $notes,
            ]);

            if ($to === OrderStatus::Confirmed) {
                $this->allocationService->allocate(
                    $order,
                    performedBy: $actor instanceof User ? (int) $actor->getKey() : null,
                );
            }

            return $order->fresh(['items', 'statusHistories']);
        });
    }
}
