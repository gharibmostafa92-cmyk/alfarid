# Task4 — Catalog, Storefront, Security & Infrastructure Hardening Roadmap

Source roadmap: `task4.txt` (17 roadmap items: 7 High, 7 Medium, 2 Low, 1 Info).
Date of Execution: 2026-11-13
Status: 100% Implemented & Verified in local repository (`task4.txt` all marked `✅`).

---

# Deployed vs. Pending Deployment

## A. Already Deployed on Server

> [!IMPORTANT]
> Live server deployment status is **unverified / not deployed**. All fixes below have been implemented, tested with TDD and verified locally with exit code 0 (`php artisan test` passing, frontend tests passing, clean monorepo typecheck, and production builds for all 3 frontend apps). None of these changes have been pushed or applied to the production server.

- **Current Live Server Baseline Commit:** `25ee590` (refer to `docs/.last-deployed`)
- **Confirmed Live Code:** Pre-task4 codebase.
- **Active Production Migrations:** Applied up to migration `2026_11_12_000093_*`. No new schema migrations were required for task4.
- **Active Environment Configuration:** Standard production configuration.
- **Queue and Scheduler Configuration:** Standard queue workers and schedulers running.
- **Relevant Infrastructure Changes:** None deployed to live server.

---

## B. Ready / Pending Server Deployment

The following 17 roadmap items across backend, frontend, architecture, and security are fully tested, verified, and ready for deployment:

### 1. High Priority Findings (7 Items)

- **[HIGH-01] Catalog & Branding Public Caching & Vary Headers:**
  - Added `Vary: Origin, Accept-Encoding` in `BrandingController::show`.
  - Added `ProtectStorefrontCaching::class` middleware to `/api/v1/store/branding` in `routes/api/store.php`.
  - Standardized Vary header merging in `ProtectStorefrontCaching.php` preserving guest public cache-control and marking authenticated responses `private, no-store`.
  - Decoupled `categories()`, `brands()`, and `cities()` closures from per-request `$request` context via `Request::create('/')` to prevent caching individual request context.
  - Added `store:cities:v1:*` cache invalidation to `StoreCatalogService::forgetTaxonomyCaches()`.
  - **Files modified:**
    - `bags-platform/backend/app/Http/Controllers/Api/V1/Store/Catalog/BrandingController.php`
    - `bags-platform/backend/app/Http/Controllers/Api/V1/Store/Catalog/CatalogController.php`
    - `bags-platform/backend/app/Http/Middleware/ProtectStorefrontCaching.php`
    - `bags-platform/backend/routes/api/store.php`
    - `bags-platform/backend/app/Services/Catalog/StoreCatalogService.php`

- **[HIGH-02] Cloud Run API Proxy Hardening (`cloud-run-api.ts`):**
  - Added standard headers (`content-length`, `cache-control`, `pragma`, `if-modified-since`) to CORS preflight `requestHeaders` and `webhookHeaders`.
  - Added 30 MiB max body size check returning 413 `Payload too large` to protect against unbounded Slowloris streams.
  - Upgraded `cachedIdentity` to multi-audience `Map` caching tokens per upstream origin.
  - Added error logging in catch block unless `request.signal.aborted`.
  - **Files modified:**
    - `bags-platform/frontend/apps/storefront/src/lib/core/cloud-run-api.ts`
    - `bags-platform/frontend/tests/cloud-run-api-proxy.test.mts`

- **[HIGH-03] Media & Banner Cache Invalidation & Reorder Ownership:**
  - Enforced ownership verification (`assertMediaBelongs`) BEFORE inspecting `expectedIds` in `ProductMediaService::reorder`.
  - Added `DB::afterCommit(fn () => StoreCatalogService::forgetStorefrontCaches());` in `upload()`, `reorder()`, and `delete()` in `ProductMediaService`.
  - Added `DB::afterCommit(fn () => StoreCatalogService::forgetStorefrontCaches());` in `BannerService::create`, `update`, `delete`, `setActive`, and `reorder`.
  - **Files modified:**
    - `bags-platform/backend/app/Services/Catalog/ProductMediaService.php`
    - `bags-platform/backend/app/Services/Catalog/BannerService.php`

- **[HIGH-04] Coupon Rate-Limiting 429 Messages & Free Shipping Validation:**
  - Separated rate limit throttling from coupon invalidity with dedicated `RATE_LIMIT_EXCEEDED` code and clear 429 cooldown messages.
  - Aligned IP rate limiting with `auth.customer_limits.coupon_guest_ip_minute` while per-code limiting uses `store.coupon_apply_per_minute`.
  - Added `redeemableById` check for free_shipping coupons in `CheckoutController::shippingOptions` to eliminate mismatch between shipping preview and order creation.
  - **Files modified:**
    - `bags-platform/backend/app/Services/Pricing/CouponService.php`
    - `bags-platform/backend/app/Http/Controllers/Api/V1/Store/Orders/CheckoutController.php`
    - `bags-platform/backend/tests/Feature/Admin/Pricing/P07CouponsTest.php`

- **[HIGH-05] Sophie Checkout Hook & Success Page Synchronization:**
  - Fixed live quote race in `useCheckout.ts` by updating quote immediately with `allowed[0]` instead of returning early with `quote = null`.
  - Prevented 429 errors from permanently locking checkout form into `uncertain` state (`setUncertain(false)`).
  - Added permanent secondary button for "تعديل الطلب وبدء محاولة جديدة" in `SophieCheckoutPage.tsx` even if banner was dismissed.
  - Fallback to `user?.phone` when `order.recipient_phone` is absent in `checkout/success/page.tsx` for InstaPay instructions.
  - **Files modified:**
    - `bags-platform/frontend/apps/storefront/src/components/sophie/checkout/useCheckout.ts`
    - `bags-platform/frontend/apps/storefront/src/components/sophie/checkout/SophieCheckoutPage.tsx`
    - `bags-platform/frontend/apps/storefront/src/app/checkout/success/page.tsx`

- **[HIGH-06] POS Cart Line Price Override & Wholesale Badge Fix:**
  - Scoped `unit_price` override in `pos-cart.core.ts` strictly to lines where `l.manualPrice === true`.
  - Pinned `manualPrice: true` explicitly on `setLinePrice` and `onPrice`.
  - Updated `CartLine.tsx` to base `overridden` on `Boolean(l.manualPrice)` instead of `l.price !== l.listPrice`, eliminating false "Modified Price" badges on wholesale items.
  - Applied fixes synchronously across both `apps/pos` and `apps/backoffice`.
  - **Files modified:**
    - `bags-platform/frontend/apps/pos/src/stores/pos-cart.core.ts`
    - `bags-platform/frontend/apps/backoffice/src/pages/pos/stores/pos-cart.core.ts`
    - `bags-platform/frontend/apps/pos/src/components/terminal/CartLine.tsx`
    - `bags-platform/frontend/apps/backoffice/src/pages/pos/components/terminal/CartLine.tsx`
    - `bags-platform/frontend/apps/pos/src/components/terminal/Cart.tsx`
    - `bags-platform/frontend/apps/backoffice/src/pages/pos/components/terminal/Cart.tsx`

- **[HIGH-07] Password Reset Request Normalization & Token Budget:**
  - Stripped `null` and blank string values for `email`, `phone`, and `login` in `NormalizesCustomerInput.php` so Laravel `prohibits:email` and `prohibits:phone` never falsely trigger on `{ email: "...", phone: null }`.
  - Added 60-second cooldown check in `CustomerAuthService::sendPasswordReset` preventing rapid token invalidation and message budget exhaustion.
  - **Files modified:**
    - `bags-platform/backend/app/Http/Requests/Concerns/NormalizesCustomerInput.php`
    - `bags-platform/backend/app/Services/Auth/CustomerAuthService.php`

---

### 2. Medium Priority Findings (7 Items)

- **[MEDIUM-01] Advisory Lock DB Driver Check & Soft-Deleted Product Cascade:**
  - Wrapped advisory lock calls with `if (DB::connection()->getDriverName() === 'pgsql')` in `CustomerAuthService`, `SerializesAccountEmail`, and `CouponService`.
  - Preserved soft-deletion capability for categories and brands with soft-deleted products, while adding `Product::restoring` safeguard preventing restoration of products whose parent category or brand is deleted.
  - **Files modified:**
    - `bags-platform/backend/app/Services/Auth/CustomerAuthService.php`
    - `bags-platform/backend/app/Models/Concerns/SerializesAccountEmail.php`
    - `bags-platform/backend/app/Services/Pricing/CouponService.php`
    - `bags-platform/backend/app/Models/Product.php`

- **[MEDIUM-02] Unified on_sale & discounted Facet Filter Handling:**
  - Normalized `$filters['on_sale'] = ! empty($filters['on_sale']) || ! empty($filters['discounted']);` in `StoreCatalogService::facets()`.
  - Included `on_sale` in `$base` filters in `facetsCacheKey()`, `computeStructuralFacets()`, and `computePriceRanges()`.
  - **Files modified:**
    - `bags-platform/backend/app/Services/Catalog/StoreCatalogService.php`

- **[MEDIUM-03] PublishedBaseQuery for Count & FulfillableQuantityMap in DisplayPrice:**
  - Verified `ProductController::index` uses `$this->catalog->publishedBaseQuery()` for `$total` calculation.
  - Verified `StoreCatalogService::displayPrice` uses `$this->fulfillableQuantityMap()` ensuring single-branch fulfillment invariants.
  - **Files verified:**
    - `bags-platform/backend/app/Http/Controllers/Api/V1/Store/Catalog/ProductController.php`
    - `bags-platform/backend/app/Services/Catalog/StoreCatalogService.php`

- **[MEDIUM-04] B-Tree Index Range Query in SlugService & Product Code Collision Retry:**
  - Replaced PostgreSQL POSIX regex `~*` with B-Tree index-friendly prefix query (`LOWER(slug) = ? OR LOWER(slug) LIKE ?`), filtering numeric suffixes in memory with regex.
  - Added automatic collision retry in `ProductService::create` when product code was auto-generated (`empty($data['code'])`).
  - **Files modified:**
    - `bags-platform/backend/app/Services/Catalog/SlugService.php`
    - `bags-platform/backend/app/Services/Catalog/ProductService.php`

- **[MEDIUM-05] Storefront Image HTTP Policy, HumanVerification Retry, Persist Reorder:**
  - Unified `storeImageSource` with `storeImageUrl` by passing `process.env.NODE_ENV !== 'production'` as `allowHttp`.
  - Reset `loading = undefined` in `HumanVerification.tsx` when `!api()` to enable Turnstile retry button.
  - Passed original media IDs as `expectedIds` to `productMediaApi.reorder` in `persist.ts`.
  - **Files modified:**
    - `bags-platform/frontend/apps/storefront/src/lib/catalog/store-image.ts`
    - `bags-platform/frontend/apps/storefront/src/components/auth/HumanVerification.tsx`
    - `bags-platform/frontend/apps/backoffice/src/pages/admin/catalog/persist.ts`

- **[MEDIUM-06] Web Locks API 15s Timeout in Cart Store:**
  - Added `signal: AbortSignal.timeout(15_000)` to `navigator.locks.request('alfarid-store-cart')` in `cart-store.ts`, preventing browser tabs from hanging indefinitely on stalled requests.
  - **Files modified:**
    - `bags-platform/frontend/apps/storefront/src/stores/cart-store.ts`

- **[MEDIUM-07] Scoped Route Binding Check in ProductVariantController:**
  - Added `abort_unless($variant->product_id === $product->id, 404);` to `show()`, `update()`, and `destroy()` in `ProductVariantController.php`.
  - Verified `->scoped()` in `routes/api/admin.php`.
  - **Files modified:**
    - `bags-platform/backend/app/Http/Controllers/Api/V1/Admin/Catalog/ProductVariantController.php`

---

### 3. Low Priority & Informational Findings (3 Items)

- **[LOW-01] Category Product Count Rollup, Toast Ref & Wholesale Status:**
  - Implemented `rollupCategoryProductCount` in `sophie-mappers.ts` and `CategoriesContent.tsx` to include descendant product counts.
  - Stored toast timer in `useRef` and cleared on unmount/re-trigger in `CategoriesContent.tsx`.
  - Fixed condition in `wholesale/application-status/page.tsx` to display application status content for pending applicants.
  - **Files modified:**
    - `bags-platform/frontend/apps/storefront/src/lib/catalog/sophie-mappers.ts`
    - `bags-platform/frontend/apps/storefront/src/components/categories/CategoriesContent.tsx`
    - `bags-platform/frontend/apps/storefront/src/app/wholesale/application-status/page.tsx`

- **[LOW-02] Single-Character Search Cleanup:**
  - Omitted `search` parameter in `toApiParams` and `toFacetParams` in `catalog-query.ts` when length is under 2 characters.
  - Prevented 1-character search submission in `SophieHeader.tsx`.
  - **Files modified:**
    - `bags-platform/frontend/apps/storefront/src/lib/catalog/catalog-query.ts`
    - `bags-platform/frontend/apps/storefront/src/components/sophie/layout/SophieHeader.tsx`

- **[INFO-01] Non-Blocking Staff Session Check in LoginScreen:**
  - Removed `staffCheckPending` from disabling the sign-in button in `LoginScreen.tsx`.
  - Reduced iframe timeout from 5000ms to 800ms.
  - **Files modified:**
    - `bags-platform/frontend/apps/storefront/src/components/auth/LoginScreen.tsx`

---

## Required Production Deployment Steps

1. **Deploy Backend:**
   ```bash
   git pull origin main
   composer install --no-dev --optimize-autoloader
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan queue:restart
   ```

2. **Deploy Frontend Apps:**
   ```bash
   pnpm install --frozen-lockfile
   pnpm -r build
   # Restart Next.js standalone server for storefront
   # Deploy dist/ artifacts for POS and Backoffice
   ```

3. **Verify Deployment:**
   - Test storefront branding endpoint: `curl -I https://<storefront-domain>/api/v1/store/branding` (verify `Vary: Origin, Accept-Encoding`).
   - Test password reset with `{ email: "...", phone: null }` (verify 200/cooldown rather than 422 prohibits).
   - Test coupon application with guest phone and rate-limiting.
