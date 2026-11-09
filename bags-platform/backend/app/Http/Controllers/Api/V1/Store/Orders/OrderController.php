<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store\Orders;

use App\Enums\Orders\OrderStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Store\Orders\OrderResource;
use App\Http\Resources\Store\Orders\TrackOrderResource;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Orders\OrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Handles storefront customer order tracking, history listing, and self-service cancellation.
 */
class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    /**
     * Look up public order progress by order number and phone number verification.
     *
     * @param  Request  $request  Incoming request containing recipient phone.
     * @param  string  $number  Unique public order number identifier.
     * @return TrackOrderResource Redacted tracking details.
     */
    public function track(Request $request, string $number): TrackOrderResource
    {
        $request->validate(['phone' => ['required', 'string', 'max:50']]);

        $order = Order::query()
            ->where('order_number', $number)
            ->with(['items', 'statusHistories', 'customer'])
            ->first();

        // Return generic 404 on phone mismatch to prevent phone/order enumeration.
        if ($order === null || ! $order->matchesPhone((string) $request->input('phone'))) {
            abort(404);
        }

        return TrackOrderResource::make($order);
    }

    /**
     * Paginate historical orders for the currently authenticated customer.
     *
     * @param  Request  $request
     * @return AnonymousResourceCollection Collection of full customer order resources.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var Customer $customer */
        $customer = $request->user();

        $orders = Order::query()
            ->where('customer_id', $customer->id)
            ->with(['items.variant.product.media', 'items.variant.media'])
            ->latest('id')
            ->paginate(15);

        return OrderResource::collection($orders);
    }

    /**
     * Cancel an active order by the customer before warehouse fulfillment begins.
     *
     * @param  Request  $request
     * @param  int  $order  Internal order identifier.
     * @return OrderResource Updated cancelled order resource.
     *
     * @throws BusinessException If order is already in processing, shipped, or delivered.
     */
    public function cancel(Request $request, int $order): OrderResource
    {
        /** @var Customer $customer */
        $customer = $request->user();

        $order = Order::query()->where('customer_id', $customer->id)->findOrFail($order);

        $cancellableOrAlreadyCancelled = [OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Cancelled];
        if (! in_array($order->status, $cancellableOrAlreadyCancelled, true)) {
            throw new BusinessException('لا يمكن إلغاء الطلب في حالته الحالية؛ الإلغاء متاح قبل بدء التجهيز والشحن فقط.');
        }

        // Pass allowed statuses to changeStatus to enforce concurrency guards under lock against concurrent processing transitions.
        $order = $this->orders->changeStatus($order, OrderStatus::Cancelled, $customer, 'ألغاه العميل من حسابه',
            $cancellableOrAlreadyCancelled);

        return OrderResource::make($order->loadMissing(['items.variant.product.media', 'items.variant.media']));
    }
}
