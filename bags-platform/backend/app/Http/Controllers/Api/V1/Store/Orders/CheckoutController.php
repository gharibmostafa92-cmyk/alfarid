<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store\Orders;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\Orders\CheckoutRequest;
use App\Http\Requests\Store\Orders\ShippingOptionsRequest;
use App\Http\Resources\Store\Orders\OrderResource;
use App\Models\Cart;
use App\Models\City;
use App\Models\Coupon;
use App\Models\Customer;
use App\Services\Orders\CartService;
use App\Services\Orders\OrderService;
use App\Services\Shared\SettingService;
use App\Services\Orders\ShippingQuoteService;
use App\Services\Pos\StorePaymentPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles storefront checkout, shipping option quotes, and order placement.
 */
class CheckoutController extends Controller
{
    public function __construct(
        private readonly CartService $carts,
        private readonly OrderService $orders,
        private readonly ShippingQuoteService $shippingQuote,
        private readonly SettingService $settings,
        private readonly StorePaymentPolicy $paymentPolicy,
    ) {}

    /**
     * Calculate and return available shipping methods, fees, and order cost previews.
     *
     * @param  ShippingOptionsRequest  $request  Validated destination city and payment method.
     * @return JsonResponse Shipping options breakdown with expected order totals.
     */
    public function shippingOptions(ShippingOptionsRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $city = City::query()->where('is_active', true)->findOrFail((int) $validated['city_id']);
        [$customer, $cart] = $this->currentCart($request, create: false);
        $totals = $this->carts->totals($cart, $customer);

        // Base shipping tier calculations on net merchandise value after promotional discounts.
        $merchandise = max($totals['items_total'] - $totals['coupon_discount'], 0.0);
        $methods = $this->paymentPolicy->allowedFor($customer);
        $method = in_array($validated['payment_method'] ?? null, $methods, true)
            ? $validated['payment_method'] : ($methods[0] ?? null);
        $cod = $method === 'cod';
        $quote = $this->shippingQuote->quoteForCity($city->id, $merchandise, $cod);
        $rate = $quote['rate'];

        // Free shipping promotions waive delivery costs but do not eliminate COD cash-handling fees.
        $cost = $quote['cost'];
        if ($totals['coupon_code'] !== null && $cart->coupon_id !== null
            && Coupon::query()->whereKey($cart->coupon_id)->value('type') === 'free_shipping') {
            $cost = 0.0;
        }

        $days = $rate !== null ? "من {$rate->estimated_days_min} إلى {$rate->estimated_days_max} أيام عمل" : null;

        // Pre-compute expected total so the client can submit an expected_total guard to prevent price drift.
        $discountAmount = round($totals['pricing_discount'] + $totals['coupon_discount'], 2);
        $taxAmount = 0.0;
        if ($this->settings->getBool('tax_enabled') && ! $this->settings->getBool('prices_include_tax')) {
            $taxRate = (float) $this->settings->get('default_tax_rate', 0);
            $taxAmount = round(($totals['subtotal'] - $discountAmount) * $taxRate / 100, 2);
        }
        $grandTotal = round(($totals['subtotal'] - $discountAmount) + $cost + $quote['cod_fee'] + $taxAmount, 2);

        return response()->json(['data' => [
            'city' => ['id' => $city->id, 'name_ar' => $city->name_ar, 'name_en' => $city->name_en],
            'cart_total' => $totals['total'],
            'payment_methods' => $methods,
            'preview' => [
                'items_total' => $totals['items_total'],
                'coupon_discount' => $totals['coupon_discount'],
                'shipping' => $cost,
                'cod_fee' => $quote['cod_fee'],
                'tax' => $taxAmount,
                'grand_total' => $grandTotal,
            ],
            'options' => [[
                'key' => 'standard',
                'label' => 'شحن قياسي',
                'cost' => $cost,
                'cod_fee' => $quote['cod_fee'],
                'currency' => 'EGP',
                'estimated_days' => $days,
            ]],
        ]]);
    }

    /**
     * Create an order from the active shopping cart for authenticated or guest sessions.
     *
     * @param  CheckoutRequest  $request  Validated customer address and payment inputs.
     * @return JsonResponse Created order resource and payment instructions.
     *
     * @throws BusinessException If guest contact details are missing or stock is unavailable.
     */
    public function store(CheckoutRequest $request): JsonResponse
    {
        [$customer, $cart] = $this->currentCart($request);
        $data = $request->validated();

        // Support Idempotency-Key header to prevent duplicate orders on network retries.
        $idempotencyKey = $request->header('Idempotency-Key');
        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            $data['idempotency_key'] = $idempotencyKey;
        }

        // Guest checkouts require explicit recipient name and phone because no profile exists.
        if ($customer === null && (empty($data['recipient_name']) || empty($data['recipient_phone']))) {
            throw new BusinessException('بيانات المستلم (الاسم ورقم الهاتف) مطلوبة لإتمام الشراء كزائر.');
        }

        $order = $this->orders->createFromCart($cart, $data, $customer);

        return response()->json([
            'data' => OrderResource::make($order)->resolve($request),
            // TODO: populate payment_url dynamically once external payment gateway integration is configured.
            'payment_url' => null,
            'message' => 'تم إنشاء الطلب بنجاح',
        ], 201);
    }

    /**
     * Resolve the active customer and associated cart session.
     *
     * @param  Request  $request  Current HTTP request with session headers.
     * @param  bool  $create  Whether to initialize a persistent cart if none exists.
     * @return array{0: ?Customer, 1: Cart}
     */
    private function currentCart(Request $request, bool $create = true): array
    {
        $user = auth('sanctum')->user();

        // Suspended or unapproved customers fall back to guest privileges to prevent account abuse.
        $customer = $user instanceof Customer && $user->canShop() ? $user : null;
        $cart = $this->carts->resolveCart($customer, $request->header('X-Cart-Session'), $create);

        return [$customer, $cart];
    }
}
