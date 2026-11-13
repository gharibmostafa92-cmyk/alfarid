# Task 10 — Security, Storefront Caching, Catalog Integrity & POS Hardening Deployment Status

Source roadmap: `_archive_unused/tasks_execution/task10.txt` (`F01`–`F19`, 19 findings).  
Date: 2026-11-12  
Branch: `fixes/task-audit-roadmap` (local working tree; **not deployed to live production**)

---

# Deployed vs. Pending Deployment

### A. Already Deployed on Server

- **Live server verification unavailable:** None of the `task10.txt` (`F01`–`F19`) fixes, migrations, environment changes, or frontend rebuilds are deployed to live Cloud Run / Neon / hosting environments. Live production runtime status is treated as unverified against prior baseline commit `25ee590` (`docs/.last-deployed`).

### B. Ready / Pending Server Deployment

All items below are implemented and verified locally and require deployment approval, migration execution by the migration-owner role, environment variable updates, backend container rebuild, and frontend/storefront/backoffice/POS rebuilds.

#### 1. Database Migrations (Must Run with Migration-Owner Role Before App Traffic)

- **`2026_11_12_000092_add_brands_lock_version.php`** (`F11`):
  - Adds non-null `lock_version` integer column (default `1`) to `brands` with reversible `down()`, enabling optimistic concurrency control across `BrandService`, `UpdateBrandRequest`, `BrandController`, and `BrandResource`.
- **Pre-migration checks & commands:**
  1. Rehearse `php artisan migrate --force` under migration-owner credentials against a staging/Neon branch snapshot.
  2. Verify schema readiness with `php artisan db:verify-schema` before promoting traffic.

#### 2. Backend Code Changes (API Container Rebuild & Deploy)

- **Proxy Client IP Verification (`F01`):**
  - `TrustProxyClientIp` enforces timestamped HMAC signatures (`${timestamp}.${ip}`, 60-second anti-replay window) with fail-closed HTTP `503` in production if the proxy shared secret is missing, preventing spoofed client IP headers and preserving Turnstile/throttle/SMS budget isolation.
- **Storefront Branding & Catalog Caching (`F02`, `F05`, `F10`, `F12`):**
  - `BrandingController` caches branding payloads with `Cache::flexible([300, 900])` + cacheable headers and invalidates via `SettingService` `DB::afterCommit` callbacks.
  - `StoreCatalogService::facets()` normalizes and caches `search` queries (`[30, 120]` seconds) under `Cache::lock` with atomic `store:facets:version` invalidation, folds redundant count queries, and caps `ProductFilterRequest` `page` parameter at `100`.
  - `CategoryService`, `BrandService`, `VariantService`, and `CatalogRetailPriceObserver` flush storefront caches (`store:categories:v1:*`, `store:brands:v1:*`, `StoreCatalogService::forgetStorefrontCaches()`, atomic facet version bump) inside `DB::afterCommit`.
  - `StoreCatalogService::applyDiscountedFilter()` restricts discounted price list subqueries to public retail price lists (`price_lists.customer_group_id IS NULL`) and `homeSectionIds()` expands `discounted_ids` sampling (`24`) so non-public or out-of-stock discounted variants do not empty storefront sale sections.
- **Customer Shopper Eligibility & Token Lifetime (`F03`, `F04`, `F13`):**
  - `Customer::resolveShopper()`, `StoreCatalogService::customer()`, `CartController`, `CheckoutController`, and `UnifiedAuthController::login()` share consistent shopper eligibility (`canShop()`), allowing `wholesale_pending` customers to retain retail shopping/cart flows without forced rejection.
  - Lowered default customer Sanctum token expiration (`config/auth.php` / `CustomerAuthService::issueToken`) from `4320` minutes to `1440` minutes.
- **Media Lock, Coupon Identity & Catalog Integrity (`F08`, `F09`, `F11`, `F16`, `F17`, `F19`):**
  - `ProductMediaService`: Extended `withMediaLock()` TTL to `240s` (exceeding 180s upload timeout), wrapped `delete()` and video uploads inside the same per-product lock, and unified polymorph owner identity checks via `$owner->getKey()`.
  - `CouponService`: Switched coupon usage advisory lock from 32-bit `hashtext` to 64-bit `pg_advisory_xact_lock(hashtextextended(..., 0))`, normalized empty normalized phone strings (`''`) to `null`, and enforced `BusinessException` when `$requireIdentity === true` lacks identity.
  - `Brand` / `BrandService` / `CategoryService` / `ProductMediaController`: Added `lock_version` optimistic concurrency checks (`expected_version`) and `expected_ids` reorder validation.
  - `ProductService` & `OrderItem`: Added `variant()->withTrashed()` on `OrderItem` to preserve customer order history/images, blocked `forceDelete` and active-order `delete` via `OrderItem` existence checks (`BusinessException`), and mapped product `code`/`create_key` unique constraint collisions cleanly to HTTP `409`.
  - Verified `WalletController` + `FinancePresenter` read committed ledger rows via `useWritePdo()` without introducing N+1 queries (`F19`).

#### 3. Frontend Package & Application Changes (Rebuild Required)

- **Cloud Run Proxy Signing (`F01`, `F02`, `F04`):**
  - `apps/storefront/src/lib/core/cloud-run-api.ts`: Added configurable `TRUSTED_PROXY_HOPS` extraction from `X-Forwarded-For` and timestamped HMAC-SHA256 client IP signing (`${ts}.${ip}`), omitting raw header spoofing and keeping `getServerBranding` within `1500ms` timeout.
  - `apps/storefront/src/app/api/auth/route.ts`: Removed dead unverified `/api/auth` cookie endpoint.
  - Updated storefront CSP/proxy headers & `StoreBrandingProvider` to avoid duplicate initial client fetches when SSR branding is preloaded.
- **Storefront Auth, Checkout, Cart Sync, Navigation & Accessibility (`F03`, `F07`, `F13`, `F14`, `F15`, `F18`):**
  - `auth.service.ts` & `AccountForm.tsx`: Preserved `guestCartSession` across pending/rejected wholesale login paths (`F03`) and restored email identifier support in `recoveryIdentifier()` (`F13`).
  - `useCheckout.ts` & `cart-store.ts` (`F07`): Stored `pending_checkout` in `localStorage` with 15-minute `expiresAt`, cart fingerprint, and `rawForm` restoration; cleared `pending_checkout` on `resetCartIdentity()`; surfaced an explicit info banner when `payment_methods` yields no supported checkout method.
  - `use-cart-sync.ts`, `use-auth-me.ts`, `RequireActor.tsx`, `SophieHeader.tsx`, and `packages/api-client/src/endpoints/store.ts` (`F14`): Added 15-second focus reload throttle, shared React Query `useAuthMe()` (`staleTime: 60_000`) eliminating duplicate `/auth/me` calls, and returned canonical `emptyStoreCart()` on HTTP `204` cart clears/removals without follow-up `GET`.
  - `SophieHeader.tsx`, `SophieMegaDropdown.jsx`, `SophieFooter.tsx`, `SophieProductsSection.tsx`, `CategoriesContent.tsx`, `categories.css`, and `AccountMenu.tsx` (`F15`, `F18`): Routed Offers links to `STORE_ROUTES.shop({ on_sale: 1 })`, replaced internal `<a>` tags with `next/link` `<Link>`, added `cancelIdleCallback` cleanup, replaced `display: none` checkbox/radio hiding with accessible `.sr-only` + `onChange`, and restored visible Arabic labels on `AccountMenu` links/buttons.
- **Admin Catalog Optimistic Concurrency (`F11`):**
  - `packages/api-client/src/endpoints/catalog.ts`: Added optional `expected_version` / `expected_ids` support on `categoryFormData`, `categoriesApi.remove`, `brandsApi.remove`, `variantsApi.remove`, and `productMediaApi.reorder`.
- **POS Math, Discount Clamp, Stock Caps & Mock Detachment (`F06`):**
  - Synced both `apps/pos/src` and `apps/backoffice/src/pages/pos`: added `parsePositivePrice`, `parseNonNegativeAmount`, and cent-exact `computeCartTotals` in `lib/money.ts`; cached item metadata on `PosCartLine` (`pos-cart.core.ts`, `pos.types.ts`, `PosApp.tsx`); removed `posMockService` coupling and `if (!p) return null` drops from `CartLine`, `Cart`, `Catalog`, and checkout components; clamped `discount` dynamically in `Cart` via `useEffect`; capped `+` quantity stepper against available stock.

#### 4. Environment, Queue, Scheduler & Operational Commands

- **New / Updated Environment Variables (Do NOT commit secrets):**
  - `TRUSTED_PROXY_HOPS` (integer >= 0, storefront Next.js server runtime, e.g. `1` behind Cloud Run / Cloudflare LB) for accurate right-hand proxy hop stripping in `cloud-run-api.ts`.
  - Ensure proxy signing secret (`PROXY_CLIENT_IP_SECRET` / configured API secret) is present in both storefront and backend production secrets (backend fails closed with `503` in production if empty).
- **Queue / Scheduler / Workers:**
  - Restart PHP workers / API containers after deploying so updated observers, token TTL defaults, and cache invalidation listeners are active.
- **Operational Deployment Commands (in order):**
  1. `php artisan migrate --force` (with migration-owner credentials).
  2. `php artisan db:verify-schema`.
  3. `php artisan config:cache && php artisan route:cache`.
  4. `pnpm -r build` and deploy updated Storefront SSR, Backoffice, and POS bundles, then restart queue workers / Cloud Run services.
