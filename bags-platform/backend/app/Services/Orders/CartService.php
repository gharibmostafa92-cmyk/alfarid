<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Exceptions\BusinessException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Services\Catalog\StoreCatalogService;
use App\Services\Pricing\CouponService;
use App\Services\Pricing\PricingService;
use App\Services\Shared\DiscreteQuantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Manages customer and guest shopping carts and real-time line item pricing.
 *
 * Guests are identified by cart_token (transmitted via X-Cart-Session header).
 * Authenticated users link carts by customer_id, merging any guest carts on login.
 * Prices and availability are resolved in real-time without caching stale amounts.
 */
class CartService
{
    public const MAX_REQUEST_QTY = 10000;

    public function __construct(
        protected PricingService $pricing,
        protected StoreCatalogService $catalog,
        protected CouponService $coupons,
    ) {}

    /**
     * Resolves the active cart for an authenticated customer or guest session token.
     *
     * In read-only mode ($create=false), returns an unpersisted Cart instance to prevent database bloat from web crawlers.
     */
    public function resolveCart(?Customer $customer, ?string $guestToken, bool $create = true): Cart
    {
        $guestToken = $guestToken !== null && Str::isUuid($guestToken) ? $guestToken : null;

        if ($customer !== null) {
            $cart = $create
                ? $this->accountCart($customer)
                : (Cart::query()->where('customer_id', $customer->id)->first() ?? new Cart(['customer_id' => $customer->id]));
            if ($create && $guestToken !== null && $guestToken !== $cart->cart_token) {
                $this->mergeGuestCart($customer, $guestToken);
                $cart = $cart->fresh();
            }

            return $cart->loadMissing('items');
        }

        $cart = $guestToken !== null
            ? Cart::query()->where('cart_token', $guestToken)->whereNull('customer_id')->first()
            : null;
        if ($cart !== null) {
            return $cart->loadMissing('items');
        }

        if (! $create) {
            return (new Cart(['cart_token' => $guestToken]))->loadMissing('items');
        }
        if ($guestToken !== null) {
            try {
                $created = Cart::query()->createOrFirst(
                    ['cart_token' => $guestToken],
                    ['customer_id' => null],
                );
            } catch (UniqueConstraintViolationException $exception) {
                if (! Cart::query()->where('cart_token', $guestToken)->whereNotNull('customer_id')->exists()) {
                    throw $exception;
                }
                $created = null;
            }
            if ($created !== null && $created->customer_id === null) {
                return $created->loadMissing('items');
            }
        }

        return Cart::create([])->loadMissing('items');
    }

    /**
     * Retrieves or creates the unique persistent cart instance for an authenticated customer.
     *
     * @param  Customer  $customer  Target customer
     * @return Cart Active customer cart
     */
    private function accountCart(Customer $customer): Cart
    {
        // createOrFirst wraps insertion inside a transaction savepoint to prevent 25P02 transaction aborts.
        return Cart::query()->where('customer_id', $customer->id)->first()
            ?? Cart::query()->createOrFirst(['customer_id' => $customer->id]);
    }

    /**
     * Merges items and coupon from a guest cart into an authenticated customer cart on login.
     *
     * @param  Customer  $customer  Target authenticated customer
     * @param  string|null  $guestToken  Guest session UUID token
     * @return bool False only when a valid guest token was supplied but the cart was not consumed
     */
    public function mergeGuestCart(Customer $customer, ?string $guestToken): bool
    {
        $guestToken = $guestToken !== null && Str::isUuid($guestToken) ? $guestToken : null;
        if ($guestToken === null) {
            return true;
        }

        // Lock both carts in ID order so concurrent merges share the same lock order.
        return DB::transaction(function () use ($customer, $guestToken): bool {
            $guestId = Cart::query()->where('cart_token', $guestToken)->whereNull('customer_id')->value('id');
            if ($guestId === null) {
                return false;
            }
            $accountId = $this->accountCart($customer)->id;
            $carts = Cart::query()->whereKey([$guestId, $accountId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $guest = $carts->get($guestId);
            $account = $carts->get($accountId);
            if ($guest === null) {
                return false;
            }
            $guestItems = $guest->items()->get();
            if ($guestItems->isEmpty()) {
                $guest->delete();

                return true;
            }

            $purchasableIds = $this->retailVariants()->whereKey($guestItems->pluck('product_variant_id')->all())
                ->pluck('id')->flip();
            $available = $this->catalog->fulfillableQuantityMap(
                $purchasableIds->keys()->map(fn ($id): int => (int) $id)->values()->all()
            );
            // Enforce cart line and item quantity caps during guest cart merger.
            $maxLines = (int) config('store.max_cart_lines', 50);
            $lineCap = $this->lineQtyCap($customer);
            $accountQty = $account->items()->pluck('qty', 'product_variant_id')
                ->map(fn ($qty): float => (float) $qty)->all();
            $accountLines = count($accountQty);
            $rows = [];
            $timestamp = now();

            foreach ($guestItems as $item) {
                $variantId = (int) $item->product_variant_id;
                if (! $purchasableIds->has($variantId)) {
                    continue;
                }
                $existingQty = $accountQty[$variantId] ?? null;
                $isNew = $existingQty === null;

                // Skip new items exceeding max cart lines to avoid blocking user login.
                if ($isNew && $maxLines > 0 && $accountLines >= $maxLines) {
                    continue;
                }

                $held = (float) ($existingQty ?? 0);
                $room = (float) ($available[$variantId] ?? 0) - $held;
                if ($lineCap > 0) {
                    $room = min($room, $lineCap - $held);
                }
                if ($room <= 0) {
                    continue;
                }

                $qty = min((float) $item->qty, $room);
                $rows[] = [
                    'cart_id' => $account->id,
                    'product_variant_id' => $variantId,
                    'qty' => $qty,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];

                $accountQty[$variantId] = $held + $qty;
                if ($isNew) {
                    $accountLines++;
                }
            }
            if ($rows !== []) {
                CartItem::query()->upsert(
                    $rows,
                    ['cart_id', 'product_variant_id'],
                    ['qty' => DB::raw('cart_items.qty + EXCLUDED.qty'), 'updated_at' => now()],
                );
            }
            if ($account->coupon_id === null && $guest->coupon_id !== null) {
                $account->unsetRelation('items');
                try {
                    $itemsTotal = $this->totals($account->load('items'), $customer)['items_total'];
                    $this->coupons->redeemableById((int) $guest->coupon_id, $itemsTotal, $customer);
                    $account->update(['coupon_id' => $guest->coupon_id]);
                } catch (BusinessException) {
                    // A guest coupon the account cannot redeem must not stick on the customer cart.
                }
            }
            $guest->items()->delete();
            $guest->delete();

            return true;
        });
    }

    /**
     * Adds an item quantity to the cart under a pessimistic row lock.
     *
     * @param  Cart  $cart  Current cart instance
     * @param  Customer|null  $customer  Authenticated customer, if any
     * @param  int  $variantId  Target product variant ID
     * @param  float  $qty  Quantity to add
     * @return Cart Fresh cart instance with loaded items
     *
     * @throws BusinessException If limits, stock, or pricing rules are violated
     */
    public function addItem(Cart $cart, ?Customer $customer, int $variantId, float $qty): Cart
    {
        $this->assertWholeQuantity($qty);
        if (! $cart->exists) {
            return DB::transaction(function () use ($cart, $customer, $variantId, $qty): Cart {
                $createdCart = $customer !== null
                    ? $this->accountCart($customer)
                    : $this->resolveCart(null, $cart->cart_token);

                return $this->withLockedCart($createdCart, $customer, function (Cart $locked) use ($customer, $variantId, $qty): Cart {
                    return $this->addItemToLockedCart($locked, $customer, $variantId, $qty);
                });
            });
        }

        return $this->withLockedCart($cart, $customer, function (Cart $locked) use ($customer, $variantId, $qty): Cart {
            return $this->addItemToLockedCart($locked, $customer, $variantId, $qty);
        });
    }

    private function addItemToLockedCart(Cart $cart, ?Customer $customer, int $variantId, float $qty): Cart
    {
        $variant = $this->purchasableVariant($variantId);
        $existing = $cart->items()->where('product_variant_id', $variantId)->first();

        // Reject adding new lines when the maximum allowed cart line limit is reached.
        if ($existing === null) {
            $maxLines = (int) config('store.max_cart_lines', 50);
            if ($maxLines > 0 && $cart->items()->count() >= $maxLines) {
                throw new BusinessException("بلغت سلة التسوق سقف الأصناف ({$maxLines})؛ احذف صنفًا قبل الإضافة.");
            }
        }

        $targetQty = ($existing !== null ? (float) $existing->qty : 0) + $qty;
        $this->assertLineQty($customer, $targetQty);
        $this->assertAvailable($variantId, $targetQty);
        $this->assertPriced($variant, $customer, $targetQty);

        // Upsert increments item quantity atomically to prevent unique key race conditions.
        CartItem::query()->upsert(
            [[
                'cart_id' => $cart->id,
                'product_variant_id' => $variantId,
                'qty' => $qty,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['cart_id', 'product_variant_id'],
            ['qty' => DB::raw('cart_items.qty + EXCLUDED.qty'), 'updated_at' => now()],
        );
        $cart->touch();

        return $cart->fresh('items');
    }

    /**
     * Updates the quantity of a specific item in the cart.
     *
     * @param  Cart  $cart  Current cart instance
     * @param  Customer|null  $customer  Authenticated customer, if any
     * @param  int  $variantId  Target product variant ID
     * @param  float  $qty  New target quantity
     * @param  int|null  $itemId  Optional cart item primary key
     * @return Cart Fresh cart instance with loaded items
     *
     * @throws BusinessException If quantity, stock, or pricing rules are violated
     */
    public function updateItemQty(Cart $cart, ?Customer $customer, int $variantId, float $qty, ?int $itemId = null): Cart
    {
        $this->assertWholeQuantity($qty, allowZero: true);
        if ($qty <= 0) {
            return $this->removeItem($cart, $variantId, $customer);
        }

        return $this->withLockedCart($cart, $customer, function (Cart $cart) use ($customer, $variantId, $qty, $itemId): Cart {
            $variant = $this->purchasableVariant($variantId);
            $this->assertLineQty($customer, $qty);
            $this->assertAvailable($variantId, $qty);
            $this->assertPriced($variant, $customer, $qty);

            $item = $cart->items()->where('product_variant_id', $variantId)
                ->when($itemId !== null, fn ($query) => $query->whereKey($itemId))->firstOrFail();
            $item->update(['qty' => $qty]);
            $cart->touch();

            return $cart->fresh('items');
        });
    }

    /**
     * Removes all quantities of a product variant from the cart.
     *
     * @param  Cart  $cart  Target cart instance
     * @param  int  $variantId  Variant to remove
     * @return Cart Fresh cart instance with loaded items
     */
    public function removeItem(Cart $cart, int $variantId, ?Customer $customer = null): Cart
    {
        return $this->withLockedCart($cart, $customer, function (Cart $cart) use ($variantId): Cart {
            $cart->items()->where('product_variant_id', $variantId)->delete();
            $cart->touch();

            return $cart->fresh('items');
        });
    }

    /**
     * Removes all items from the cart.
     *
     * @param  Cart  $cart  Target cart instance
     * @return Cart Fresh cart instance with loaded items
     */
    public function clearItems(Cart $cart, ?Customer $customer = null): Cart
    {
        return $this->withLockedCart($cart, $customer, function (Cart $cart): Cart {
            $cart->items()->delete();
            $cart->touch();

            return $cart->fresh('items');
        });
    }

    /**
     * Apply a coupon while the cart row is locked, recovering onto the account cart if the guest row was deleted.
     */
    public function applyCoupon(Cart $cart, ?Customer $customer, string $code): Cart
    {
        return $this->withLockedCart($cart, $customer, function (Cart $locked) use ($customer, $code): Cart {
            $locked->load('items');
            $totals = $this->totals($locked, $customer);
            $this->coupons->validateAndApply($locked, $code, $totals['items_total'], $customer);

            return $locked;
        });
    }

    /**
     * Remove a coupon while the cart row is locked, recovering onto the account cart if the guest row was deleted.
     */
    public function removeCoupon(Cart $cart, ?Customer $customer): Cart
    {
        return $this->withLockedCart($cart, $customer, function (Cart $locked): Cart {
            $locked->update(['coupon_id' => null]);

            return $locked;
        });
    }

    /**
     * Calculates real-time prices, discounts, and line totals for all cart items.
     *
     * @return array<int, array{item_id: int, variant: ProductVariant, qty: float, unit_price: float|null, base_price: float|null, line_discount: float, line_total: float}>
     */
    public function pricedLines(Cart $cart, ?Customer $customer): array
    {
        $cart->loadMissing('items.variant.product');
        $purchasableIds = $this->retailVariants()->whereIn('id', $cart->items->pluck('product_variant_id'))->pluck('id')->all();
        $context = $this->pricing->contextFor($customer, $cart->items->pluck('product_variant_id')->all());
        $lines = [];
        foreach ($cart->items as $item) {
            $variant = $item->variant;
            if ($variant === null) {
                continue;
            }
            // Retain inactive variants in response with null prices so shoppers can view and remove them from cart.
            $qty = (float) $item->qty;
            if (! in_array($variant->id, $purchasableIds, true)) {
                $lines[] = $this->unpricedLine($item->id, $variant, $qty);

                continue;
            }
            try {
                $resolved = $this->pricing->resolve($variant, $customer, $qty, ctx: $context);
                $unit = (float) $resolved['unit_price'];
                $base = (float) $resolved['base_price'];
                $lines[] = [
                    'item_id' => $item->id,
                    'variant' => $variant,
                    'qty' => $qty,
                    'unit_price' => $unit,
                    'base_price' => $base,
                    // Customer-specific pricing higher than base price does not produce negative discounts.
                    'line_discount' => max(0.0, BigDecimal::of((string) $base)->minus((string) $unit)->multipliedBy((string) $qty)->toScale(2, RoundingMode::HalfUp)->toFloat()),
                    'line_total' => BigDecimal::of((string) $unit)->multipliedBy((string) $qty)->toScale(2, RoundingMode::HalfUp)->toFloat(),
                ];
            } catch (BusinessException) {
                $lines[] = $this->unpricedLine($item->id, $variant, $qty);
            }
        }

        return $lines;
    }

    /**
     * Calculates financial summary totals for the cart with real-time pricing and applied coupons.
     *
     * @param  array<int, array<string, mixed>>|null  $lines  Pre-computed priced lines to avoid redundant pricing calls.
     * @return array{subtotal: float, pricing_discount: float, items_total: float, coupon_discount: float, total: float, coupon_code: string|null, coupon_free_shipping: bool}
     */
    public function totals(Cart $cart, ?Customer $customer, ?array $lines = null): array
    {
        $lines ??= $this->pricedLines($cart, $customer);
        $subtotalDecimal = BigDecimal::zero();
        $itemsDecimal = BigDecimal::zero();
        foreach ($lines as $line) {
            if ($line['unit_price'] === null) {
                continue;
            }
            // Each line subtotal is rounded first, then aggregated to preserve exact currency arithmetic.
            $lineSubtotal = BigDecimal::of((string) $line['base_price'])->multipliedBy((string) $line['qty'])->toScale(2, RoundingMode::HalfUp);
            $subtotalDecimal = $subtotalDecimal->plus($lineSubtotal->isLessThan((string) $line['line_total']) ? (string) $line['line_total'] : $lineSubtotal);
            $itemsDecimal = $itemsDecimal->plus((string) $line['line_total']);
        }
        $subtotal = $subtotalDecimal->toFloat();
        $itemsTotal = $itemsDecimal->toFloat();

        // Pricing discount is the difference between rounded totals to prevent fractional cent rounding discrepancies.
        // Higher custom prices raise the base threshold to avoid negative discounts inflating the bill.
        if ($subtotal < $itemsTotal) {
            $subtotal = $itemsTotal;
            $pricingDiscount = 0.0;
        } else {
            $pricingDiscount = $subtotalDecimal->minus($itemsDecimal)->toScale(2, RoundingMode::HalfUp)->toFloat();
        }

        $couponDiscount = 0.0;
        $couponCode = null;
        $couponFreeShipping = false;
        if ($cart->coupon_id !== null && $itemsTotal > 0) {
            try {
                $coupon = $this->coupons->redeemableById((int) $cart->coupon_id, $itemsTotal, $customer);
                $couponDiscount = $this->coupons->discountFor($coupon, $itemsTotal);
                $couponCode = $coupon->code;
                $couponFreeShipping = $coupon->type === 'free_shipping';
            } catch (BusinessException) {
                // Silently omit invalidated coupons from cart totals; checkout will re-verify strictly.
            }
        }

        return [
            'subtotal' => round($subtotal, 2),
            'pricing_discount' => round($pricingDiscount, 2),
            'items_total' => round($itemsTotal, 2),
            'coupon_discount' => $couponDiscount,
            'total' => $itemsDecimal->minus((string) $couponDiscount)->toScale(2, RoundingMode::HalfUp)->toFloat(),
            'coupon_code' => $couponCode,
            'coupon_free_shipping' => $couponFreeShipping,
        ];
    }

    /**
     * Verifies that all cart line variants remain published and active for purchase.
     *
     * @param  array<int, array{variant: ProductVariant, qty: float}>  $lines
     */
    public function assertLinesPurchasable(array $lines): void
    {
        foreach ($lines as $line) {
            $this->assertWholeQuantity((float) $line['qty']);
        }
        $ids = array_map(fn (array $line): int => (int) $line['variant']->id, $lines);
        // Verify variants against published storefront channel and category hierarchy in one query batch.
        $variants = $this->retailVariants()->whereIn('id', $ids)->pluck('id')->all();
        foreach ($lines as $line) {
            $variant = $line['variant'];
            if (! in_array($variant->id, $variants, true)) {
                throw new BusinessException("الصنف ({$variant->sku}) لم يعد متاحًا للبيع؛ أزله من السلة وأعد المحاولة.");
            }
        }
    }

    /**
     * Verifies that requested quantities for all lines are available in branch stock.
     *
     * @param  array<int, array{variant: ProductVariant, qty: float}>  $lines
     */
    public function assertLinesAvailable(array $lines): void
    {
        $ids = array_map(fn (array $line): int => (int) $line['variant']->id, $lines);
        $map = $this->catalog->fulfillableQuantityMap($ids);
        foreach ($lines as $line) {
            if (($map[$line['variant']->id] ?? 0) < $line['qty']) {
                throw new BusinessException("الكمية المتاحة من الصنف ({$line['variant']->sku}) لم تعد كافية لإتمام الطلب.");
            }
        }
    }

    /**
     * Formats cart data, media urls, real-time availability, and totals for storefront presentation.
     *
     * @return array<string, mixed>
     */
    public function present(Cart $cart, ?Customer $customer): array
    {
        // Eager-load display relations up front: product + variant media for the
        // card image (same `images` collection as StoreProductCardResource via
        // Product::catalogImages — there is no `catalog` collection). Constant
        // cost regardless of line count (no N+1).
        $cart->loadMissing([
            'items.variant.media',
            'items.variant.product.media',
        ]);
        $lines = $this->pricedLines($cart, $customer);
        $ids = array_map(fn (array $line): int => (int) $line['variant']->id, $lines);
        $availability = $this->catalog->fulfillableQuantityMap($ids);

        $items = [];
        foreach ($lines as $line) {
            $variant = $line['variant'];
            $exact = (float) ($availability[$variant->id] ?? 0);
            // Cap exposed balance to purchasable limits to prevent leaking exact warehouse stock levels.
            $maxQty = $line['unit_price'] === null ? 0.0 : $this->maxPurchasableQty($exact, $customer);
            // Prefer the chosen variant image, then the parent product image.
            $first = $variant->getMedia('images')->first()
                ?? $variant->product?->getMedia('images')->first();
            $items[] = [
                'id' => $line['item_id'],
                'product_variant_id' => $variant->id,
                'product_id' => $variant->product_id,
                'product_name' => $variant->product?->name_ar,
                'product_slug' => $variant->product?->slug,
                'product_name_en' => $variant->product?->name_en,
                'image' => $first && $first->hasGeneratedConversion('thumb') ? $first->getUrl('thumb') : ($first?->getUrl() ?: null),
                'color' => $variant->color_name_en ?? $variant->color_name_ar,
                'size' => $variant->size ?? $variant->capacity,
                'sku' => $variant->sku,
                'qty' => $line['qty'],
                'unit_price' => $line['unit_price'],
                'base_price' => $line['base_price'],
                'line_discount' => $line['line_discount'],
                'line_total' => $line['line_total'],
                'available' => $maxQty,
                'max_qty' => $maxQty,
                'priced' => $line['unit_price'] !== null,
            ];
        }

        return [
            'id' => $cart->id,
            'cart_token' => $cart->cart_token,
            'customer_id' => $cart->customer_id,
            'items' => $items,
            'totals' => $this->totals($cart, $customer, $lines),
        ];
    }

    /**
     * Lock the cart row, and if a concurrent merge deleted it, re-resolve once outside the aborted transaction.
     *
     * @param  \Closure(Cart): Cart  $callback
     */
    private function withLockedCart(Cart $cart, ?Customer $customer, \Closure $callback): Cart
    {
        try {
            return $this->lockedCartTransaction($cart, $callback);
        } catch (ModelNotFoundException $exception) {
            if ($exception->getModel() !== Cart::class) {
                throw $exception;
            }

            return $this->lockedCartTransaction($this->recoveredCart($cart, $customer), $callback);
        }
    }

    /**
     * @param  \Closure(Cart): Cart  $callback
     */
    private function lockedCartTransaction(Cart $cart, \Closure $callback): Cart
    {
        return DB::transaction(function () use ($cart, $callback): Cart {
            return $callback(Cart::query()->lockForUpdate()->findOrFail($cart->id));
        });
    }

    private function recoveredCart(Cart $cart, ?Customer $customer): Cart
    {
        if ($customer !== null) {
            return $this->accountCart($customer);
        }
        if ($cart->customer_id !== null) {
            $owner = Customer::query()->find($cart->customer_id);
            if ($owner !== null) {
                return $this->accountCart($owner);
            }
        }

        return $this->resolveCart(null, $cart->cart_token);
    }

    /**
     * Retrieves an active variant published on the retail channel.
     *
     * @param  int  $variantId  Product variant ID
     * @return ProductVariant Found active variant
     *
     * @throws BusinessException If variant is unpublished or inactive
     */
    private function purchasableVariant(int $variantId): ProductVariant
    {
        // Apply storefront catalog publishing rules to ensure discontinued products cannot be added to cart.
        $variant = $this->retailVariants()->with('product')->find($variantId);
        if ($variant === null) {
            throw new BusinessException('الصنف غير متاح للشراء.');
        }

        return $variant;
    }

    /**
     * Scopes query to active variants published on storefront retail channels.
     *
     * @return Builder Eloquent query builder for retail variants
     */
    private function retailVariants(): Builder
    {
        return ProductVariant::query()->where('is_active', true)
            ->whereExists(
                $this->catalog->publishedBaseQuery()
                    ->withoutEagerLoads()
                    ->whereColumn('products.id', 'product_variants.product_id')
                    ->selectRaw('1')
            );
    }

    /**
     * Enforces maximum allowed purchase quantity per cart line for guests and retail users.
     *
     * @param  Customer|null  $customer  Customer or null for guest
     * @param  float  $qty  Target line quantity
     *
     * @throws BusinessException If requested quantity exceeds user cap
     */
    public function assertLineQty(?Customer $customer, float $qty): void
    {
        $cap = $this->lineQtyCap($customer);
        if ($cap > 0 && $qty > $cap) {
            throw new BusinessException($customer === null
                ? "سقف كمية البند الواحد للزوار ({$cap}) — سجّل الدخول للكميات الأكبر."
                : "سقف كمية البند الواحد ({$cap}) — للكميات الأكبر تواصل مع مسار الجملة.");
        }
    }

    /**
     * Returns the effective quantity cap per cart line based on customer type (0 = uncapped for wholesale).
     *
     * @param  Customer|null  $customer  Customer or null for guest
     * @return float Maximum allowed line quantity (0 = uncapped)
     */
    public function lineQtyCap(?Customer $customer): float
    {
        if ($customer === null) {
            return (float) config('store.guest_max_line_qty', 10);
        }
        if ($customer->isWholesaleApproved()) {
            return 0.0;
        }

        return (float) config('store.retail_max_line_qty', 50);
    }

    /**
     * Computes the maximum purchasable quantity considering branch availability and request caps.
     *
     * @param  float  $available  Actual branch quantity
     * @param  Customer|null  $customer  Customer or null for guest
     * @return float Capped quantity safe for storefront exposure
     */
    public function maxPurchasableQty(float $available, ?Customer $customer): float
    {
        $cap = $this->lineQtyCap($customer);

        return max(0.0, min($available, self::MAX_REQUEST_QTY, $cap > 0 ? $cap : self::MAX_REQUEST_QTY));
    }

    /**
     * Asserts that the quantity is a positive whole integer.
     *
     * @param  float  $qty  Input quantity
     * @param  bool  $allowZero  Whether 0 is considered valid
     *
     * @throws BusinessException If quantity contains fractional parts or is negative
     */
    public function assertWholeQuantity(float $qty, bool $allowZero = false): void
    {
        DiscreteQuantity::assertWhole($qty, $allowZero);
    }

    /**
     * Verifies that a single branch holds enough stock to fulfill the requested line quantity.
     * Storefront orders are not split across branches to prevent multiple shipments.
     *
     * @param  int  $variantId  Target variant
     * @param  float  $qty  Required quantity
     *
     * @throws BusinessException If insufficient branch inventory
     */
    private function assertAvailable(int $variantId, float $qty): void
    {
        $available = $this->catalog->fulfillableQuantityMap([$variantId])[$variantId] ?? 0;
        if ($available < $qty) {
            throw new BusinessException('الكمية المطلوبة غير متاحة حاليًا لهذا الصنف. يمكن تجهيز حتى '.max(0, floor($available)).' قطعة من فرع واحد.');
        }
    }

    /**
     * Ensures the variant has a valid pricing rule before adding to cart.
     *
     * @param  ProductVariant  $variant  Target variant
     * @param  Customer|null  $customer  Customer or null for guest
     * @param  float  $qty  Quantity being priced
     *
     * @throws BusinessException If no valid price rule matches
     */
    private function assertPriced(ProductVariant $variant, ?Customer $customer, float $qty): void
    {
        $this->pricing->resolve($variant, $customer, $qty);
    }

    /**
     * @return array{item_id: int, variant: ProductVariant, qty: float, unit_price: null, base_price: null, line_discount: float, line_total: float}
     */
    private function unpricedLine(int $itemId, ProductVariant $variant, float $qty): array
    {
        return [
            'item_id' => $itemId,
            'variant' => $variant,
            'qty' => $qty,
            'unit_price' => null,
            'base_price' => null,
            'line_discount' => 0.0,
            'line_total' => 0.0,
        ];
    }
}
