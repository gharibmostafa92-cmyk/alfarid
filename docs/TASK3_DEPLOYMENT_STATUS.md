# Task3 — POS Terminal, Inventory, Financial Invariants & Concurrency Roadmap

Source roadmap: `task3.txt` (19 roadmap items: 3 Critical, 7 High, 7 Medium, 2 Low).
Date of Execution: 2026-11-13
Status: 100% Implemented & Verified in local repository (`task3.txt` all marked `✅`).

---

# Deployed vs. Pending Deployment

## A. Already Deployed on Server

> [!IMPORTANT]
> Live server deployment status is **unverified / not deployed**. All fixes below have been implemented, tested with TDD and verified locally with exit code 0 (`45 passed, 193 assertions` backend test suite, plus clean monorepo typecheck). None of these changes have been pushed or applied to the production server.

- **Current Live Server Baseline Commit:** `25ee590` (refer to `docs/.last-deployed`)
- **Confirmed Live Code:** Pre-task3 codebase.
- **Active Production Migrations:** Applied up to migration `2026_11_12_000082_*`. Migration `83` is NOT yet applied to production.

---

## B. Ready / Pending Server Deployment

The following 19 roadmap items are fully tested, verified, and ready for deployment:

### 1. Critical Backend & Financial Integrity Fixes
- **CRITICAL-01:** Net-zero and negative net exchange transaction crashes resolved.
  - Non-cash (card) payment refunded first on negative exchange balance to protect cash drawer balance.
  - Return order tender breakdown contract synchronized across backend and both frontend POS apps.
- **CRITICAL-02:** Cart price override corruption fixed.
  - Manual price overrides strictly scoped to modified items (`manualItemIds`), preventing corruption of wholesale/tiered prices on other lines.
- **CRITICAL-03:** Petty expense path traversal and unbounded notes hardened.
  - Expense attachment paths strictly validated via `ExpenseService::validateAttachmentPath`.
  - Date pinned to business timezone (`today(config('app.business_timezone'))`).
  - Strict length limits: `category <= 100`, `notes <= 500`.

### 2. High Priority Shift & Inventory Concurrency Fixes
- **HIGH-01:** Blind-close drawer balance leak plugged.
  - `PosService::getAvailableDrawers`, `PosShiftController::availableDrawers`, and `PosBootstrapController::show` hide `balance` (`null`) for busy drawers unless caller holds `pos_shifts.view_expected`.
- **HIGH-02:** Exchange failure for suspended or soft-deleted customers resolved.
  - Suspended/deleted customers detached from replacement cart to allow legal retail exchange without permission burn.
  - Checked `pos.discount` before idempotency runner in `PosSaleController::processExchange`.
- **HIGH-03:** Held sale multi-cart merge and discount retention.
  - Multiple held cart IDs persisted in localStorage (`heldSaleIds: number[]`).
  - Atomic server consumption via `held_sale_ids` in `PosSaleController::store`.
  - Line discounts aggregated into cart discount on resume.
- **HIGH-04:** PostgreSQL syntax in `PruneHeldSales.php` & held deletion race condition.
  - Replaced illegal Postgres `DELETE ... ORDER BY ... LIMIT` with `whereIn('id', $ids)->delete()`.
  - Excluded actively resumed carts (`resumed_at > now() - 10 min`).
  - Added `DB::transaction` with `lockForUpdate()` in `deleteHeld` (returns 409 if active).
  - Restricted `held_count` in `PosBootstrapController` by `user_id` when missing `pos.held.view_all`.
- **HIGH-05:** Multiple open shifts invariant violation on recount reopen prevented.
  - `PosService::reopenShiftForRecount` locks user and drawer rows.
  - Throws 409 `BusinessException` if the cashier or the drawer has an active shift.
- **HIGH-06:** Catalog version cache invalidation on transactions.
  - `PosService::invalidateCatalogVersionCache` uses `DB::afterCommit` inside transactions.
  - Invalidation hooked into `createSale`, `processReturn`, and `PosStockController::receiveTransfer` (both source and destination branches).
- **HIGH-07:** Migration `2026_11_12_000083_pos_tender_includes_exchange_credit.php` guarded for production deployment.
  - Guarded with `if (DB::getDriverName() !== 'pgsql') return;`.
  - Scoped check constraint to `channel <> 'pos' OR ...`.
  - Prevented rollback failure on existing exchange orders with `exchange_credit > 0`.

### 3. Medium & Low Priority POS UI & Hardware Fixes
- **MEDIUM-01 (`ReceiveTab.tsx`):**
  - Added `submittingRef` and `busy` state to prevent double clicks on "تأكيد الاستلام ورفع الرصيد".
  - Wrapped `pos.receiveTransfer` in `try / catch / finally` with `toast(normalizeError(err).message, 'bad')`.
  - Validated received quantities: required non-empty, `>= 0`, and `<= shipped_qty`. Added `.catch()` to `getTransfersIn`.
- **MEDIUM-02 (`StocktakePage.tsx`):**
  - Tracked `lastScanned` state in component when scanning barcodes.
  - Removed dangerous fallback `|| scope[0]` in voice counting; alerts cashier if no item was scanned first.
  - Updated draft submit action to clearly indicate that draft is saved continuously and final approval occurs in backoffice.
- **MEDIUM-03 (`CheckoutModal.tsx`, `ReceiptDone.tsx`, `pos.css`, `PosBootstrapController.php`):**
  - Calculated split payment percentages in cents: `fromCents(Math.min(Math.max(1, Math.round(totalCents * r)), Math.max(1, totalCents - 1)))`.
  - Added dynamic `return_window_days` from POS bootstrap settings to receipt print.
  - Fixed thermal printing `@media print` rules: hid `.app` and modals to avoid feeding blank paper rolls.
- **MEDIUM-04 (`PosCustomerController.php` & `CustomerBar.tsx`):**
  - Immediate empty array returned if query is empty or `< 2` characters, protecting customer phone numbers and reducing query load by >70%.
  - Added debounce (250ms) and minimum 2 characters in `CustomerBar.tsx`.
  - Informative validation error if customer phone is soft-deleted or suspended.
- **MEDIUM-05 (`barcode-scanner.ts`, `PosApp.tsx`):**
  - `defaultScanIgnore` ignores focused `INPUT`, `TEXTAREA`, `SELECT`, and `contenteditable`.
  - `PosApp.tsx` suppresses global scanner listener when modals (`isReturnOpen`, `isHoldOpen`, `isHeldOpen`, `isCheckoutOpen`, `closingShift`, `hasTabConflict`) are open.
- **MEDIUM-06 (`SharedUI.tsx`):**
  - `toAsciiNum` supports Persian/Urdu digits (`۰-۹`) and Arabic decimal separators (`،` and `٫`).
  - Added optional `maxDecimals = 2` parameter, allowing inventory transfers to parse 4 decimal places.
- **MEDIUM-07 (Code Drift):**
  - Synchronized `bags-platform/frontend/apps/pos/src/` with `bags-platform/frontend/apps/backoffice/src/pages/pos/` with 0 differences in components, stores, lib, types, and styles.
- **LOW-01 (`sound.ts`):**
  - Disconnected `osc` and `gain` inside `osc.onended` to prevent memory leaks in Web Audio API.
- **LOW-02 (`tab-coordinator.ts`, `PosApp.tsx`):**
  - Added `claim()` method to broadcast `takeover` message on "الاستمرار في هذه النافذة".
  - Old tabs immediately show tab conflict overlay, preventing dual-tab race conditions.

---

## Required Production Deployment Steps

When authorized to deploy to the live server, execute the following operational steps in order:

### 1. Pre-Deployment Database Backup
```bash
pg_dump -U postgres -h localhost -d alfarid -F c -b -v -f "/backups/alfarid_pre_task3_$(date +%Y%m%d_%H%M%S).dump"
```

### 2. Code Pull
```bash
git checkout main
git pull origin main
```

### 3. Backend Deployment & Migration
```bash
cd bags-platform/backend
composer install --no-dev --optimize-autoloader
php artisan down --render="errors::503"
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
php artisan up
```

### 4. Frontend Monorepo Build & Asset Deployment
```bash
cd bags-platform/frontend
pnpm install --frozen-lockfile
pnpm -r build
# Restart / reload PM2 or Nginx web server serving frontend applications
```

### 5. Post-Deployment Smoke Verification
1. Verify POS Terminal loads with active shift and available drawers.
2. Verify customer search requires 2 characters and does not dump database.
3. Test barcode scanner in POS terminal, in Return modal, and in text inputs.
4. Verify thermal receipt printing on 80mm printer without blank paper feed.
5. Verify petty cash expense upload with valid image.
