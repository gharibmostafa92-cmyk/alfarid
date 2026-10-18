<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store;

use App\Enums\CustomerStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\Cart\AddCartItemRequest;
use App\Http\Requests\Store\Cart\ApplyCouponRequest;
use App\Http\Requests\Store\Cart\UpdateCartItemRequest;
use App\Models\Cart;
use App\Models\Customer;
use App\Services\CartService;
use App\Services\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * سلة المتجر — تعمل للزائر (هيدر X-Cart-Session) وللعميل المسجل (Bearer)
 * بنفس النقاط؛ الهوية تُحسم داخل CartService.
 */
class CartController extends Controller
{
    public function __construct(
        private readonly CartService $carts,
        private readonly CouponService $coupons,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // قراءة فقط: لا يُحفظ صف سلة لمجرد العرض (M3)
        [$customer, $cart] = $this->currentCart($request, create: false);

        return response()->json(['data' => $this->carts->present($cart, $customer)]);
    }

    public function store(AddCartItemRequest $request): JsonResponse
    {
        [$customer, $cart] = $this->currentCart($request);
        $cart = $this->carts->addItem($cart, $customer, (int) $request->validated()['product_variant_id'], (float) $request->validated()['qty']);

        return response()->json(['data' => $this->carts->present($cart, $customer)]);
    }

    public function update(UpdateCartItemRequest $request, int $item): JsonResponse
    {
        [$customer, $cart] = $this->currentCart($request);
        $cartItem = $cart->items()->findOrFail($item);
        $cart = $this->carts->updateItemQty($cart, $customer, (int) $cartItem->product_variant_id, (float) $request->validated()['qty']);

        return response()->json(['data' => $this->carts->present($cart, $customer)]);
    }

    public function destroy(Request $request, int $item): JsonResponse
    {
        [$customer, $cart] = $this->currentCart($request);
        $cartItem = $cart->items()->findOrFail($item);
        $cart = $this->carts->removeItem($cart, (int) $cartItem->product_variant_id);

        return response()->json(['data' => $this->carts->present($cart, $customer)]);
    }

    public function removeCoupon(Request $request): JsonResponse
    {
        [$customer, $cart] = $this->currentCart($request);
        $cart->update(['coupon_id' => null]);

        return response()->json(['data' => $this->carts->present($cart->refresh(), $customer)]);
    }

    public function applyCoupon(ApplyCouponRequest $request): JsonResponse
    {
        [$customer, $cart] = $this->currentCart($request);
        $totals = $this->carts->totals($cart, $customer);
        $discount = $this->coupons->validateAndApply($cart, (string) $request->validated()['code'], $totals['items_total'], $customer);

        return response()->json(['data' => [
            'cart' => $this->carts->present($cart->fresh(), $customer),
            'coupon_discount' => $discount,
        ]]);
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
