<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Exceptions\BusinessException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\ProductVariant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
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

        return ($create ? Cart::create([]) : new Cart)->loadMissing('items');
    }

    /**
     * Retrieves or creates the unique persistent cart instance for an authenticated customer.
     */
    private function accountCart(Customer $customer): Cart
    {
        // createOrFirst wraps insertion inside a transaction savepoint to prevent 25P02 transaction aborts.
        return Cart::query()->where('customer_id', $customer->id)->first()
            ?? Cart::query()->createOrFirst(['customer_id' => $customer->id]);
    }

    /**
     * Merges items and coupon from a guest cart into an authenticated customer cart on login.
     */
    public function mergeGuestCart(Customer $customer, ?string $guestToken): void
    {
        $guestToken = $guestToken !== null && Str::isUuid($guestToken) ? $guestToken : null;
        if ($guestToken === null) {
            return;
        }

        // Lock guest cart under transaction to serialize concurrent post-login requests.
        DB::transaction(function () use ($customer, $guestToken): void {
            $guest = Cart::query()->where('cart_token', $guestToken)->whereNull('customer_id')
                ->lockForUpdate()->first();
            if ($guest === null) {
                return;
            }
            if (! $guest->items()->exists()) {
                $guest->delete();

                return;
            }

            $account = Cart::query()->lockForUpdate()->findOrFail($this->accountCart($customer)->id);
            // Enforce cart line and item quantity caps during guest cart merger.
            $maxLines = (int) config('store.max_cart_lines', 50);
            $lineCap = $this->lineQtyCap($customer);
            $accountQty = $account->items()->pluck('qty', 'product_variant_id')
                ->map(fn ($qty): float => (float) $qty)->all();
            $accountLines = count($accountQty);

            foreach ($guest->items as $item) {
                $variantId = (int) $item->product_variant_id;
                $existingQty = $accountQty[$variantId] ?? null;
                $isNew = $existingQty === null;

                // Skip new items exceeding max cart lines to avoid blocking user login.
                if ($isNew && $maxLines > 0 && $accountLines >= $maxLines) {
                    continue;
                }

                $qty = (float) $item->qty;
                if ($lineCap > 0) {
                    $allowed = $lineCap - (float) ($existingQty ?? 0);
                    if ($allowed <= 0) {
                        continue;
                    }
                    $qty = min($qty, $allowed);
                }

                // Upsert combines quantities atomically to avoid unique constraint collisions.
                CartItem::query()->upsert(
                    [[
                        'cart_id' => $account->id,
                        'product_variant_id' => $variantId,
                        'qty' => $qty,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]],
                    ['cart_id', 'product_variant_id'],
                    ['qty' => DB::raw('cart_items.qty + EXCLUDED.qty'), 'updated_at' => now()],
                );

                $accountQty[$variantId] = (float) ($existingQty ?? 0) + $qty;
                if ($isNew) {
                    $accountLines++;
                }
            }
            if ($account->coupon_id === null && $guest->coupon_id !== null) {
                $account->update(['coupon_id' => $guest->coupon_id]);
            }
            $guest->items()->delete();
            $guest->delete();
        });
    }

    /**
     * Adds an item quantity to the cart under a pessimistic row lock.
     */
    public function addItem(Cart $cart, ?Customer $customer, int $variantId, float $qty): Cart
    {
        $this->assertWholeQuantity($qty);

        return DB::transaction(function () use ($cart, $customer, $variantId, $qty): Cart {
            // Lock cart row to serialize concurrent item additions and enforce line limits.
            if (! $cart->exists) {
                $cart = $customer !== null ? $this->accountCart($customer) : Cart::create([]);
            }
            $cart = Cart::query()->lockForUpdate()->findOrFail($cart->id);

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

            return $cart->fresh('items');
        });
    }

    /**
     * Updates the quantity of a specific item in the cart.
     */
    public function updateItemQty(Cart $cart, ?Customer $customer, int $variantId, float $qty, ?int $itemId = null): Cart
    {
        $this->assertWholeQuantity($qty, allowZero: true);
        if ($qty <= 0) {
            return $this->removeItem($cart, $variantId);
        }

        return DB::transaction(function () use ($cart, $customer, $variantId, $qty, $itemId): Cart {
            $cart = Cart::query()->lockForUpdate()->findOrFail($cart->id);
            $variant = $this->purchasableVariant($variantId);
            $this->assertLineQty($customer, $qty);
            $this->assertAvailable($variantId, $qty);
            $this->assertPriced($variant, $customer, $qty);

            $item = $cart->items()->where('product_variant_id', $variantId)
                ->when($itemId !== null, fn ($query) => $query->whereKey($itemId))->firstOrFail();
            $item->update(['qty' => $qty]);

            return $cart->fresh('items');
        });
    }

    /**
     * Removes all quantities of a product variant from the cart.
     */
    public function removeItem(Cart $cart, int $variantId): Cart
    {
        return DB::transaction(function () use ($cart, $variantId): Cart {
            $cart = Cart::query()->lockForUpdate()->findOrFail($cart->id);
            $cart->items()->where('product_variant_id', $variantId)->delete();

            return $cart->fresh('items');
        });
    }

    /**
     * Removes all items from the cart.
     */
    public function clearItems(Cart $cart): Cart
    {
        return DB::transaction(function () use ($cart): Cart {
            $cart = Cart::query()->lockForUpdate()->findOrFail($cart->id);
            $cart->items()->delete();

            return $cart->fresh('items');
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
            // L1: soft-deleted or deactivated variants stay in the response as
            // unavailable lines so the shopper can remove them.
            if (! in_array($variant->id, $purchasableIds, true)) {
                $lines[] = [
                    'item_id' => $item->id,
                    'variant' => $variant,
                    'qty' => (float) $item->qty,
                    'unit_price' => null,
                    'base_price' => null,
                    'line_discount' => 0.0,
                    'line_total' => 0.0,
                ];

                continue;
            }
            $qty = (float) $item->qty;
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
                $lines[] = [
                    'item_id' => $item->id,
                    'variant' => $variant,
                    'qty' => $qty,
                    'unit_price' => null,
                    'base_price' => null,
                    'line_discount' => 0.0,
                    'line_total' => 0.0,
                ];
            }
        }

        return $lines;
    }

    /**
     * Calculates financial summary totals for the cart with real-time pricing and applied coupons.
     *
     * @param  array<int, array<string, mixed>>|null  $lines  Pre-computed priced lines to avoid redundant pricing calls.
     * @return array{subtotal: float, pricing_discount: float, items_total: float, coupon_discount: float, total: float, coupon_code: string|null}
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
        if ($cart->coupon_id !== null && $itemsTotal > 0) {
            try {
                $coupon = $this->coupons->redeemableById((int) $cart->coupon_id, $itemsTotal, $customer);
                $couponDiscount = $this->coupons->discountFor($coupon, $itemsTotal);
                $couponCode = $coupon->code;
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
        // H1: one batched refresh; the purchasability rule is the storefront
        // published rule (retail channel + active category path), not just
        // is_active/Published/trashed.
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
        $map = $this->catalog->availabilityMap($ids);
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
            'items.variant.product.media',
            'items.variant.product.variants.media',
        ]);
        $lines = $this->pricedLines($cart, $customer);
        $ids = array_map(fn (array $line): int => (int) $line['variant']->id, $lines);
        $availability = $this->catalog->availabilityMap($ids);

        $items = [];
        foreach ($lines as $line) {
            $variant = $line['variant'];
            $exact = (float) ($availability[$variant->id] ?? 0);
            // L2: never expose the exact branch-wide balance; shoppers only
            // need the purchasable ceiling. Unpriced lines report zero.
            $maxQty = $line['unit_price'] === null ? 0.0 : $this->maxPurchasableQty($exact, $customer);
            // Same image rule as StoreProductCardResource::toArray: first of
            // Product::catalogImages() (product `images`, then active variants'
            // `images`), thumb conversion when generated, original as fallback.
            $images = $variant->product !== null ? $variant->product->catalogImages() : collect();
            $first = $images->first();
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
     * Retrieves an active variant published on the retail channel.
     */
    private function purchasableVariant(int $variantId): ProductVariant
    {
        // H1: the single source of truth is the storefront published rule.
        $variant = $this->retailVariants()->with('product')->find($variantId);
        if ($variant === null) {
            throw new BusinessException('الصنف غير متاح للشراء.');
        }

        return $variant;
    }

    /**
     * Scopes query to active variants published on storefront retail channels.
     */
    private function retailVariants(): Builder
    {
        return ProductVariant::query()->where('is_active', true)
            ->whereIn('product_id', $this->catalog->publishedQuery()->withoutEagerLoads()->select('products.id'));
    }

    /**
     * Enforces maximum allowed purchase quantity per cart line for guests and retail users.
     */
    private function assertLineQty(?Customer $customer, float $qty): void
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
     */
    public function lineQtyCap(?Customer $customer): float
    {
        if ($customer === null) {
            return (float) config('store.guest_max_line_qty', 10);
        }
        if ($customer->status === CustomerStatus::Approved
            && in_array($customer->type, [CustomerType::Wholesale, CustomerType::Distributor], true)) {
            return 0.0;
        }

        return (float) config('store.retail_max_line_qty', 50);
    }

    /**
     * Computes the maximum purchasable quantity considering branch availability and request caps.
     */
    public function maxPurchasableQty(float $available, ?Customer $customer): float
    {
        $cap = $this->lineQtyCap($customer);

        return max(0.0, min($available, self::MAX_REQUEST_QTY, $cap > 0 ? $cap : self::MAX_REQUEST_QTY));
    }

    /**
     * Asserts that the quantity is a positive whole integer.
     */
    public function assertWholeQuantity(float $qty, bool $allowZero = false): void
    {
        if (! is_finite($qty) || floor($qty) !== $qty || $qty < ($allowZero ? 0 : 1)) {
            throw new BusinessException('الكمية يجب أن تكون عددًا صحيحًا من القطع.');
        }
    }

    /**
     * Verifies variant availability against aggregate stock.
     */
    private function assertAvailable(int $variantId, float $qty): void
    {
        $available = $this->catalog->availabilityMap([$variantId])[$variantId] ?? 0;
        if ($available < $qty) {
            throw new BusinessException('الكمية المطلوبة غير متاحة حاليًا لهذا الصنف.');
        }
    }

    /**
     * Ensures the variant has a valid pricing rule before adding to cart.
     */
    private function assertPriced(ProductVariant $variant, ?Customer $customer, float $qty): void
    {
        $this->pricing->resolve($variant, $customer, $qty);
    }
}
