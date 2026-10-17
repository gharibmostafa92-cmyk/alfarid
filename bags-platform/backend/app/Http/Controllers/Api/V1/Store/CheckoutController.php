<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\Checkout\CheckoutRequest;
use App\Http\Requests\Store\Checkout\ShippingOptionsRequest;
use App\Http\Resources\Store\OrderResource;
use App\Http\Resources\Store\TrackOrderResource;
use App\Models\Cart;
use App\Models\City;
use App\Models\Customer;
use App\Models\Order;
use App\Services\CartService;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** إتمام الشراء (زائر/مسجل) وخيارات الشحن وتتبع الطلب العام. */
class CheckoutController extends Controller
{
    public function __construct(
        private readonly CartService $carts,
        private readonly OrderService $orders,
    ) {}

    public function shippingOptions(ShippingOptionsRequest $request): JsonResponse
    {
        $city = City::query()->where('is_active', true)->findOrFail((int) $request->validated()['city_id']);
        [$customer, $cart] = $this->currentCart($request);
        $totals = $this->carts->totals($cart, $customer);

        return response()->json(['data' => [
            'city' => ['id' => $city->id, 'name_ar' => $city->name_ar, 'name_en' => $city->name_en],
            'cart_total' => $totals['total'],
            'options' => [[
                'key' => 'standard',
                'label' => 'شحن قياسي',
                'cost' => (float) $city->shipping_cost,
                'currency' => 'EGP',
                'estimated_days' => 'من 2 إلى 4 أيام عمل',
            ]],
        ]]);
    }

    public function checkout(CheckoutRequest $request): JsonResponse
    {
        [$customer, $cart] = $this->currentCart($request);
        $data = $request->validated();

        if ($customer === null && (empty($data['recipient_name']) || empty($data['recipient_phone']))) {
            throw new BusinessException('بيانات المستلم (الاسم ورقم الهاتف) مطلوبة لإتمام الشراء كزائر.');
        }

        $order = $this->orders->createFromCart($cart, $data, $customer);

        return response()->json([
            'data' => OrderResource::make($order)->resolve($request),
            // لا بوابة دفع مدمجة بعد: الدفع الإلكتروني يرجع رابطًا فارغًا حتى ربط البوابة
            'payment_url' => null,
            'message' => 'تم إنشاء الطلب بنجاح',
        ], 201);
    }

    /** تتبع عام: رقم الطلب + رقم هاتف الطلب للتحقق. */
    public function track(Request $request, string $number): TrackOrderResource
    {
        $request->validate(['phone' => ['required', 'string', 'max:50']]);

        $order = Order::query()
            ->where('order_number', $number)
            ->with(['items', 'statusHistories'])
            ->first();

        $phoneMatches = $order !== null
            && ($order->guest_phone === $request->input('phone')
                || $order->customer?->phone === $request->input('phone'));

        if (! $phoneMatches) {
            abort(404);
        }

        return TrackOrderResource::make($order);
    }

    /** @return array{0: ?Customer, 1: Cart} */
    private function currentCart(Request $request): array
    {
        $user = auth('sanctum')->user();
        $customer = $user instanceof Customer ? $user : null;
        $cart = $this->carts->resolveCart($customer, $request->header('X-Cart-Session'));

        return [$customer, $cart];
    }
}
