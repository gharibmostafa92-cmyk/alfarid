<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store\Orders;

use App\Enums\Orders\OrderStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Store\Orders\OrderResource;
use App\Http\Resources\Store\Orders\TrackOrderResource;
use App\Services\Pos\StorePaymentPolicy;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Orders\OrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\RateLimiter;

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

        $ipRateKey = 'order-track-ip:'.sha1($number.'|'.$request->ip());
        $orderRateKey = 'order-track-order:'.sha1($number);
        if (RateLimiter::tooManyAttempts($ipRateKey, 5) || RateLimiter::tooManyAttempts($orderRateKey, 40)) {
            abort(429, 'محاولات كثيرة لتتبع هذا الطلب؛ حاول لاحقًا.');
        }

        $order = Order::query()
            ->leftJoin('customers as tracking_customer', 'tracking_customer.id', '=', 'orders.customer_id')
            ->where('orders.order_number', $number)
            ->select('orders.*', 'tracking_customer.phone as tracking_phone')
            ->first();

        // Constant-length HMAC comparison prevents timing/length side-channels even when $order is null.
        $phone = Customer::normalizePhone((string) $request->input('phone'));
        $hmacKey = (string) config('app.key');
        $phoneHmac = hash_hmac('sha256', $phone, $hmacKey);
        $match = false;
        foreach ([$order?->guest_phone_normalized, $order?->guest_phone, $order?->tracking_phone] as $candidate) {
            $candNorm = Customer::normalizePhone((string) $candidate);
            $matches = hash_equals(hash_hmac('sha256', $candNorm, $hmacKey), $phoneHmac);
            $match = $match || ($candidate !== null && $matches);
        }
        if ($order === null || $phone === '' || ! $match) {
            RateLimiter::hit($ipRateKey, 900);
            RateLimiter::hit($orderRateKey, 900);
            abort(404);
        }
        RateLimiter::clear($ipRateKey);

        // Only hydrate line items and history after the caller proves phone ownership.
        $order->loadMissing(['items', 'statusHistories']);

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

        $request->validate([
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $orders = Order::query()
            ->where('customer_id', $customer->id)
            ->with(['items.variant.product' => fn ($q) => $q->withTrashed()->with('media'), 'items.variant.media'])
            ->latest('id')
            ->paginate(15);

        return OrderResource::collection($orders);
    }

    /**
     * Return a single order belonging to the authenticated customer by public number.
     */
    public function show(Request $request, string $number): OrderResource
    {
        /** @var Customer $customer */
        $customer = $request->user();

        $order = Order::query()
            ->where('customer_id', $customer->id)
            ->where('order_number', $number)
            ->with(['items.variant.product' => fn ($q) => $q->withTrashed()->with('media'), 'items.variant.media'])
            ->firstOrFail();

        $order->setAttribute('instapay', app(StorePaymentPolicy::class)->instapayInstructions($order));

        return OrderResource::make($order);
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

        return OrderResource::make($order->loadMissing(['items.variant.product' => fn ($q) => $q->withTrashed()->with('media'), 'items.variant.media']));
    }
}
