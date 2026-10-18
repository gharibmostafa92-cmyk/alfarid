<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Store\OrderResource;
use App\Http\Resources\Store\TrackOrderResource;
use App\Models\Customer;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * طلبات المتجر: تتبع عام (برقم الطلب + هاتف الطلب)،
 * وطلبات حساب العميل المسجل وعرضها وإلغاؤها الذاتي.
 */
class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    /** تتبع عام: رقم الطلب + رقم هاتف الطلب للتحقق. */
    public function track(Request $request, string $number): TrackOrderResource
    {
        $request->validate(['phone' => ['required', 'string', 'max:50']]);

        $order = Order::query()
            ->where('order_number', $number)
            ->with(['items', 'statusHistories'])
            ->first();

        // مقارنة بعد توحيد صيغة الهاتف على الجانبين (M5)
        $inputPhone = Customer::normalizePhone((string) $request->input('phone'));
        $phoneMatches = $order !== null
            && (($order->guest_phone !== null && Customer::normalizePhone($order->guest_phone) === $inputPhone)
                || ($order->customer?->phone !== null && Customer::normalizePhone($order->customer->phone) === $inputPhone));

        if (! $phoneMatches) {
            abort(404);
        }

        return TrackOrderResource::make($order);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var Customer $customer */
        $customer = $request->user();

        $orders = Order::query()
            ->where('customer_id', $customer->id)
            ->with('items')
            ->latest('id')
            ->paginate(15);

        return OrderResource::collection($orders);
    }

    public function cancel(Request $request, int $order): OrderResource
    {
        /** @var Customer $customer */
        $customer = $request->user();

        $order = Order::query()->where('customer_id', $customer->id)->findOrFail($order);

        if (! in_array($order->status, [OrderStatus::Pending, OrderStatus::Confirmed], true)) {
            throw new BusinessException('لا يمكن إلغاء الطلب في حالته الحالية؛ الإلغاء متاح قبل بدء التجهيز والشحن فقط.');
        }

        // الفحص على النسخة المقفولة داخل changeStatus (F-11): الفحص أعلاه
        // على نسخة غير مقفولة لا يكفي ضد انتقال متزامن إلى Processing
        $order = $this->orders->changeStatus($order, OrderStatus::Cancelled, $customer, 'ألغاه العميل من حسابه',
            [OrderStatus::Pending, OrderStatus::Confirmed]);

        return OrderResource::make($order->load('items'));
    }
}
