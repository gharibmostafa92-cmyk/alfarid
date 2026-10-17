<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Store\OrderResource;
use App\Models\Customer;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** طلبات العميل المسجل: العرض والإلغاء الذاتي قبل الشحن. */
class AccountOrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

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

    public function cancel(Request $request, int $id): OrderResource
    {
        /** @var Customer $customer */
        $customer = $request->user();

        $order = Order::query()->where('customer_id', $customer->id)->findOrFail($id);

        if (! in_array($order->status, [OrderStatus::Pending, OrderStatus::Confirmed], true)) {
            throw new BusinessException('لا يمكن إلغاء الطلب في حالته الحالية؛ الإلغاء متاح قبل بدء التجهيز والشحن فقط.');
        }

        $order = $this->orders->changeStatus($order, OrderStatus::Cancelled, $customer, 'ألغاه العميل من حسابه');

        return OrderResource::make($order->load('items'));
    }
}
