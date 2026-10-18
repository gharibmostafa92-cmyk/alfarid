<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store;

use App\Enums\CustomerStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\Checkout\CheckoutRequest;
use App\Http\Requests\Store\Checkout\ShippingOptionsRequest;
use App\Http\Resources\Store\OrderResource;
use App\Models\Cart;
use App\Models\City;
use App\Models\Coupon;
use App\Models\Customer;
use App\Services\CartService;
use App\Services\OrderService;
use App\Services\ShippingQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** إتمام الشراء (زائر/مسجل) وخيارات الشحن. */
class CheckoutController extends Controller
{
    public function __construct(
        private readonly CartService $carts,
        private readonly OrderService $orders,
        private readonly ShippingQuoteService $shippingQuote,
    ) {}

    public function shippingOptions(ShippingOptionsRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $city = City::query()->where('is_active', true)->findOrFail((int) $validated['city_id']);
        [$customer, $cart] = $this->currentCart($request, create: false);
        $totals = $this->carts->totals($cart, $customer);

        // نفس المدخلات التي سيحاسب عليها إتمام الشراء (M12 + F-29): البضاعة
        // بعد خصم الكوبون، ورسوم COD حسب طريقة الدفع الفعلية لا cod دائمًا
        $merchandise = max($totals['items_total'] - $totals['coupon_discount'], 0.0);
        $cod = ($validated['payment_method'] ?? 'cod') === 'cod';
        $quote = $this->shippingQuote->quoteForCity($city->id, $merchandise, $cod);
        $rate = $quote['rate'];

        // كوبون الشحن المجاني يعفي تكلفة الشحن (لا رسوم COD) كما عند الشراء
        $cost = $quote['cost'];
        if ($totals['coupon_code'] !== null && $cart->coupon_id !== null
            && Coupon::query()->whereKey($cart->coupon_id)->value('type') === 'free_shipping') {
            $cost = 0.0;
        }

        $days = $rate !== null ? "من {$rate->estimated_days_min} إلى {$rate->estimated_days_max} أيام عمل" : null;

        return response()->json(['data' => [
            'city' => ['id' => $city->id, 'name_ar' => $city->name_ar, 'name_en' => $city->name_en],
            'cart_total' => $totals['total'],
            'options' => [[
                'key' => 'standard',
                'label' => 'شحن قياسي',
                'cost' => $cost,
                'cod_fee' => $quote['cod_fee'],
                'currency' => 'EGP',
                'estimated_days' => $days ?? 'من 2 إلى 4 أيام عمل',
            ]],
        ]]);
    }

    public function store(CheckoutRequest $request): JsonResponse
    {
        [$customer, $cart] = $this->currentCart($request);
        $data = $request->validated();
        // هيدر اختياري: إعادة الإرسال بنفس المفتاح ترجع الطلب نفسه (F-13)
        $idempotencyKey = $request->header('Idempotency-Key');
        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            $data['idempotency_key'] = $idempotencyKey;
        }

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

    /** @return array{0: ?Customer, 1: Cart} */
    private function currentCart(Request $request, bool $create = true): array
    {
        $user = auth('sanctum')->user();
        // عميل موقوف/مرفوض بتوكن صالح يُعامل كزائر (F-20): لا تُربط سلاته
        // وطلباته بحساب غير نشط ولا تُمنح له أسعار/حدود العميل
        $customer = $user instanceof Customer
            && ! in_array($user->status, [CustomerStatus::Suspended, CustomerStatus::Rejected], true)
                ? $user
                : null;
        $cart = $this->carts->resolveCart($customer, $request->header('X-Cart-Session'), $create);

        return [$customer, $cart];
    }
}
