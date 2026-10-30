<?php

declare(strict_types=1);

namespace App\Http\Resources\Wholesale;

use App\Enums\OrderStatus;
use App\Enums\ReturnStatus;
use App\Enums\WholesalePaymentNoticeStatus;
use App\Http\Resources\Store\OrderResource;
use App\Models\ReturnRequest;
use App\Models\WholesalePaymentNotice;
use Illuminate\Http\Request;

/**
 * تمثيل طلب الجملة: يرث حقول Store\OrderResource ويضيف اسم المدينة
 * وسجل الحالات وأعلام الإلغاء/الإرجاع.
 */
class WholesaleOrderResource extends OrderResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $base = parent::toArray($request);

        /** @var \App\Models\Order $order */
        $order = $this->resource;

        $histories = $order->relationLoaded('statusHistories')
            ? $order->statusHistories
            : $order->statusHistories()->orderBy('id')->get();

        $base['city_name'] = $order->relationLoaded('city')
            ? $order->city?->name_ar
            : $order->city?->name_ar;

        $base['status_history'] = $histories->map(fn ($h) => [
            'status' => $h->to_status instanceof OrderStatus ? $h->to_status->value : (string) $h->to_status,
            'at' => $h->created_at?->toIso8601String(),
        ])->values()->all();

        $base['cancellable'] = $this->isCancellable($order);
        $base['returnable'] = $this->isReturnable($order);

        return $base;
    }

    private function isCancellable(\App\Models\Order $order): bool
    {
        if (! in_array($order->status, [OrderStatus::Pending, OrderStatus::Confirmed], true)) {
            return false;
        }

        if ($order->payment_method === 'credit' && WholesalePaymentNotice::query()
            ->where('order_id', $order->id)
            ->where('status', WholesalePaymentNoticeStatus::Approved->value)
            ->exists()) {
            return false;
        }

        return true;
    }

    private function isReturnable(\App\Models\Order $order): bool
    {
        if ($order->status !== OrderStatus::Delivered) {
            return false;
        }

        return ! ReturnRequest::query()
            ->where('order_id', $order->id)
            ->whereNotIn('status', [ReturnStatus::Rejected->value, ReturnStatus::Cancelled->value])
            ->exists();
    }
}
