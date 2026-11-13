# Task5 — Deployment Status: POS, Inventory & Finance Hardening

Source roadmap: `task5.txt` (POS, Inventory, Pricing, Stocktaking & Cart Security — 21 Roadmap Items: CRITICAL 01–02, HIGH 01–06, MEDIUM 01–09, LOW 01–03, INFO 01).
Date: 2026-11-13

---

# Deployed vs. Pending Deployment

### A. Already Deployed on Server

- **Live Server Baseline:**
  - Deployment marker (`docs/.last-deployed`): `25ee590`.
  - Cloud Run Europe (`europe-west3`, `alfarid-api`): revision `alfarid-api-rel20261110b`.
  - Production Neon Database: Runtime role `alfarid_pilot_runtime` (cannot execute DDL in `public`; all migrations must be applied with the migration-owner role).
- **Task5 Deployment Confirmation:**
  - Live production Cloud Run / Neon access was **not** used during this task.
  - None of the 21 items or code changes implemented in `task5.txt` have been deployed to production yet.
  - Current live server status for all Task5 items: **Unverified / Not Deployed (Pending)**.

---

### B. Ready / Pending Server Deployment

All 21 items have been implemented, tested, and verified locally in the working tree. They are packaged and ready for staged rehearsal and production release:

#### 1. Backend Changes & Route Hardening

1. **`PosService.php` (`[CRITICAL-01]`, `[HIGH-02]`, `[MEDIUM-01]`, `[MEDIUM-02]`, `[MEDIUM-05]`):**
   - **`chainTenderCaps`**: Implemented BFS Connected Component Traversal across both upstream (`exchange_order_id`, `return_of_order_id`) and downstream (`return_of_order_id`, child exchanges) orders, locking all component orders in ascending ID order (`lockForUpdate()`), aggregating net cash and card paid vs. net cash and card refunded across the entire graph. Eliminates tender cap inflation and prevents drawer drainage across branched exchanges.
   - **`createSale`**: Enforced active resume lease check (`resumed_by` / `resumed_at > now() - 5m`) on held sales. Throws `409 Conflict` if leased by another cashier, preventing double-selling.
   - **`lookupProducts`**: Discards suspended or inactive customers (`!$customer->canShop()`) from price list resolution. Removed unused `media` relationship eager loading from barcode lookup query.
   - **Sequence Numbering**: Switched `createSale` and `processReturn` to consume the dedicated `pos_invoice` numbering setting (`generateNextNumber('pos_invoice')`), isolating branch POS locks from web/wholesale order numbering.
   - **`processExchange`**: Forward price overrides when the cashier has `pos.override_price` authorization.

2. **`routes/api/pos.php` (`[CRITICAL-02]`, `[HIGH-05]`, `[LOW-03]`):**
   - Added `permission:pos.sales.create` middleware guards to `/sales`, `/sales/hold`, `/sales/quote`, `/sales/held/*`, `/customers`, `/shifts/open`, `/shifts/close`, and `/treasuries/available`.
   - Extracted `/stocktakes/current` and `/stocktakes/{stocktake}/scan` from the `pos.shift` middleware group so stock counters without open shifts can count stock.
   - Guarded `/catalog`, `/products/lookup`, and `/stock` with `permission:pos.sales.create|inventory.view` to prevent blind stocktake information leakage.
   - Registered `POST /api/v1/pos/sales/expenses` route.

3. **`PosSaleController.php` (`[MEDIUM-02]`, `[MEDIUM-08]`, `[LOW-03]`):**
   - Added `recordExpense` controller action delegating to `PosService::recordExpense`.
   - Enforced array size limits: `items.*` max:100 in `processReturn`, `returned_items` & `exchange_items` max:100 in `processExchange`, `held_sale_ids` max:20 in `store`.
   - Authorized `pos.override_price` permission when custom `unit_price` is sent in `processExchange`.

4. **`PosProductController.php` (`[MEDIUM-01]`):**
   - Filtered out non-shoppable customers (`$customer->canShop()`) in `lookup`.

5. **`PosShiftController.php` (`[MEDIUM-04]`):**
   - Safe null checking on `cashier`, `treasury`, and `branch` relations in `formatShift` to prevent 500 error when associated records are soft-deleted.

6. **`Controller.php` (`[MEDIUM-06]`):**
   - Unified strict regex check `/\A[A-Za-z0-9._:-]{1,128}\z/` in `Controller::idempotencyKey` preventing 500 crashes from malformed UTF-8 bytes.

7. **Database Migrations & Indexes (`[INFO-01]`):**
   - Verified that redundant partial index `pos_shifts_single_open_unique` was dropped in migration `2026_11_12_000040_remove_redundant_indexes_and_check.php`, leaving the master index `pos_shifts_one_open_user`.

---

#### 2. Frontend Applications & Packages

1. **`packages/pos-core/src/lib/money.ts` (`[HIGH-03]`):**
   - Replaced floating-point multiplication in `toCents` with string-based decimal parsing, eliminating binary float rounding errors.

2. **`packages/pos-core/src/components/returns/ReturnModal.tsx` (`[HIGH-01]`, `[HIGH-03]`, `[MEDIUM-02]`):**
   - Added `exchangeCashPart` input for mixed payments when `net > 0`, validated `0 < cash < net`, and passed `cashAmount` to `pos.processExchange`.
   - Migrated all line and total refund calculations to integer cents (`lineRefundCents`, `retTotalCents`, `netCents`).
   - Propagated quoted unit prices to exchange item payloads.

3. **`packages/pos-core/src/services/pos-api.core.ts` (`[HIGH-01]`, `[MEDIUM-02]`, `[MEDIUM-03]`):**
   - Added validation for `mixed` payments requiring both cash and card amounts.
   - Removed redundant `this.quoteSale(...)` call before `processExchange`.
   - Flushed all cached singleton state (shifts, held sales, catalog etag, search index, drafts) in `syncActor` when user or branch changes.

4. **`packages/pos-core/src/components/PosApp.tsx` (`[HIGH-02]`):**
   - Pruned deleted held sale server IDs from `posCartStore.heldSaleIds`.
   - Preserved manual price overrides when resuming and merging held sales into the cart.

5. **`packages/pos-core/src/lib/pending-sale.ts` (`[HIGH-04]`):**
   - Added `safeSetItem` to prevent hard crashes on storage quota or restricted storage errors.
   - Cleared unsubmitted drafts when user cancels or aborts request, preventing permanent 409 lockout.

6. **`packages/pos-core/src/components/inventory/StocktakePage.tsx` (`[HIGH-06]`):**
   - Maintained stable `scan_id` across retry attempts to prevent double counting on network drops.

7. **`packages/pos-core/src/components/inventory/TransfersPage.tsx` (`[MEDIUM-07]`):**
   - Handled promise rejections in `loadTransfers` to avoid uncaught promise exceptions.

8. **`packages/pos-core/src/components/terminal/CustomerBar.tsx` (`[MEDIUM-07]`):**
   - Converted Arabic digits with `toAsciiNum(q)` before pre-filling customer modal.

9. **`packages/pos-core/src/components/terminal/ScanBar.tsx` (`[MEDIUM-07]`):**
   - Guarded against picking out-of-stock items (`stock <= 0`).

10. **`packages/pos-core/src/components/inventory/StockPage.tsx` (`[MEDIUM-07]`):**
    - Handled error states on catalog and category queries.

11. **`packages/pos-core/src/lib/print-receipt.ts` (`[MEDIUM-09]`):**
    - Delayed `host.remove()` using `afterprint` event listener + 60s fallback timeout to prevent blank receipts on non-blocking print dialogs.

12. **`packages/pos-core/src/components/checkout/CheckoutModal.tsx` (`[LOW-01]`):**
    - Added `maxLength={470}` on notes input.

13. **`apps/backoffice/src/pages/pos/PosPage.tsx` (`[LOW-02]`):**
    - Removed dynamic runtime Google Fonts link injection.

---

#### 3. Operational Deployment Steps

1. **Database Migrations:**
   - Run `php artisan migrate --force` with migration-owner role.
2. **Container Image Build & Release:**
   - Build backend API image: `docker build -t alfarid-api:<tag> bags-platform/backend`.
   - Deploy to Cloud Run: `gcloud run deploy alfarid-api --image ...`.
3. **Frontend Build & CDN Deployment:**
   - Build frontend monorepo: `pnpm -r build`.
   - Deploy `apps/pos` and `apps/backoffice` static assets to CDN / host.
4. **Post-Deployment Smoke Tests:**
   - Cashier shift open / sale / split exchange / return traversal verification.
   - Stock counter voice & barcode stocktake without shift.
   - Print receipt verification on thermal printer.
