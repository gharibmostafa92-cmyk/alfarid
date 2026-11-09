<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\Cart\AddCartItemRequest;
use App\Http\Requests\Store\Cart\ApplyCouponRequest;
use App\Http\Requests\Store\Cart\UpdateCartItemRequest;
use App\Models\Cart;
use App\Models\Customer;
use App\Services\Orders\CartService;
use App\Services\Pricing\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Manages storefront shopping cart operations, item quantities, and coupon redemptions.
 */
class CartController extends Controller
{
    public function __construct(
        private readonly CartService $carts,
        private readonly CouponService $coupons,
    ) {}

    /**
     * Display the current session or customer shopping cart.
     */
    public function index(Request $request): JsonResponse
    {
        // Read-only view: do not persist empty cart records merely on display.
        [$customer, $cart] = $this->currentCart($request, create: false);

        return response()->json(['data' => $this->carts->present($cart, $customer)]);
    }

    /**
     * Add an item to the shopping cart.
     */
    public function store(AddCartItemRequest $request): JsonResponse
    {
        [$customer, $cart] = $this->currentCart($request, create: false);
        $cart = $this->carts->addItem($cart, $customer, (int) $request->validated()['product_variant_id'], (float) $request->validated()['qty']);

        return response()->json(['data' => $this->carts->present($cart, $customer)]);
    }

    /**
     * Update quantity of an existing cart item.
     */
    public function update(UpdateCartItemRequest $request, int $item): JsonResponse
    {
        [$customer, $cart] = $this->currentCart($request);
        $cartItem = $cart->items()->findOrFail($item);
        $cart = $this->carts->updateItemQty($cart, $customer, (int) $cartItem->product_variant_id, (float) $request->validated()['qty'], $cartItem->id);

        return response()->json(['data' => $this->carts->present($cart, $customer)]);
    }

    /**
     * Remove an item from the shopping cart idempotently.
     */
    public function destroy(Request $request, int $item): JsonResponse
    {
        // Deletions are idempotent: missing carts or lines represent the desired end state.
        try {
            [$customer, $cart] = $this->currentCart($request);
        } catch (NotFoundHttpException) {
            return response()->json(null, 204);
        }
        $cartItem = $cart->items()->find($item);
        if ($cartItem === null) {
            return response()->json(null, 204);
        }
        $cart = $this->carts->removeItem($cart, (int) $cartItem->product_variant_id);

        return response()->json(['data' => $this->carts->present($cart, $customer)]);
    }

    /**
     * Detach the applied coupon from the shopping cart.
     */
    public function removeCoupon(Request $request): JsonResponse
    {
        [$customer, $cart] = $this->currentCart($request);
        $cart->update(['coupon_id' => null]);

        return response()->json(['data' => $this->carts->present($cart->refresh(), $customer)]);
    }

    /**
     * Clear all items from the shopping cart.
     */
    public function clear(Request $request): JsonResponse
    {
        // Clearing a missing cart is idempotent and returns success immediately.
        try {
            [$customer, $cart] = $this->currentCart($request);
        } catch (NotFoundHttpException) {
            return response()->json(null, 204);
        }
        if (! $cart->exists) {
            return response()->json(null, 204);
        }
        $cart = $this->carts->clearItems($cart);

        return response()->json(['data' => $this->carts->present($cart, $customer)]);
    }

    /**
     * Validate and apply a promotional coupon code to the cart.
     */
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
    private function currentCart(Request $request, bool $create = false): array
    {
        $user = auth('sanctum')->user();

        // Suspended or unapproved customers with valid tokens fall back to guest pricing and privileges.
        $customer = $user instanceof Customer && $user->canShop() ? $user : null;
        $cart = $this->carts->resolveCart($customer, $request->header('X-Cart-Session'), $create);
        if (! $cart->exists && ! $request->isMethod('GET') && $request->route()?->getActionMethod() !== 'store') {
            abort(404);
        }

        return [$customer, $cart];
    }
}
