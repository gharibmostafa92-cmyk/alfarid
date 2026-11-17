# Task4 — Operations, Finance, Commerce & Orders Hardening Roadmap

Source roadmap: `_archive_unused/tasks_execution/task4.txt` (22 items: 2 Critical, 7 High, 8 Medium, 4 Low, 1 Info).
Date of Execution: 2026-11-17
Status: Implemented & verified locally (targeted backend & frontend tests passing).

---

# Deployed vs. Pending Deployment

## A. Already Deployed on Server

> [!IMPORTANT]
> Live server deployment status is **unverified / not deployed**. All changes below were implemented and verified locally; none have been pushed or applied to the production server.

- **Current Live Server Baseline Commit:** `25ee590` (refer to `docs/.last-deployed`)
- **Confirmed Live Code:** Pre-task4 operations hardening codebase.
- **Active Production Migrations:** No new schema migrations were required for this roadmap.
- **Active Environment Configuration:** Standard production configuration.
- **Queue and Scheduler Configuration:** Standard queue workers and schedulers.
- **Relevant Infrastructure Changes:** None deployed to live server.

---

## B. Ready / Pending Server Deployment

### 1. Backend Changes (`bags-platform/backend`)

- **Unified `treasury_id` Filter (`CRITICAL-02`):**
  - `app/Http/Controllers/Api/V1/Admin/Finance/TreasuryTransferController.php`: Added `treasury_id` validation, `BranchAccess` enforcement, and `from_treasury_id = X OR to_treasury_id = X` query filtering.
- **Returns Stats Endpoint & `per_page` Support (`MEDIUM-02`):**
  - `routes/api/admin.php`: Added `GET /api/v1/admin/returns/stats`.
  - `app/Http/Controllers/Api/V1/Admin/Orders/ReturnController.php`: Added `stats()` endpoint and `per_page` validation (`1..100`) on `index()`.
- **Idempotency-Key Support (`HIGH-04`):**
  - `app/Http/Controllers/Api/V1/Admin/Orders/ReturnController.php`: Wired `OperationIdempotencyService` into `store`, `approve`, `receive`, and `refund`.
  - `app/Http/Controllers/Api/V1/Admin/Orders/WholesalePaymentNoticeController.php`: Wired `OperationIdempotencyService` into `approve`.
  - `app/Http/Controllers/Api/V1/Admin/Orders/OrderController.php`: Wired `OperationIdempotencyService` into `createShipment`.
  - `app/Http/Requests/Admin/Orders/CreateShipmentRequest.php`: Skipped `unique:shipments,awb_number` on idempotency replay so retries with the same `Idempotency-Key` replay cleanly.
  - `app/Providers/AppServiceProvider.php`: Registered `'shipment' => \App\Models\Shipment::class` in `Relation::enforceMorphMap`.

### 2. Frontend & API Client Changes (`bags-platform/frontend`)

- **API Client (`packages/api-client/src/endpoints/operations/http.ts`, `orders.ts`):**
  - Enforced `DEFAULT_MAX_PAGES = 20` in `allPages()` (`CRITICAL-02`).
  - Hardened `signedAttachmentUrl()` against insecure `http://` (non-localhost) and untrusted external hosts (`HIGH-01`), and decoded JSON `Blob` errors in `openProtectedFile()` / `downloadFile()` (`HIGH-01`).
  - Added `adminReturnsApi.stats()`, `per_page` param, and `Idempotency-Key` headers on `createShipment` and `adminReturnsApi.{create,approve,receive,refund}` (`HIGH-04`, `MEDIUM-02`).
- **Operations Shell, UI & Notifications (`OperationsMockPage.tsx`, `ui.tsx`, `useNotifications.ts`):**
  - Changed `OperationsMockPage` and `canViewOperation` to fail-closed (`HIGH-02`), added `hashchange` synchronization, and deduplicated Google Fonts `<link>` (`MEDIUM-08`).
  - Updated `Pager` with sliding window + ellipses + `aria-current="page"` + `0–0` empty state (`HIGH-03`), `Dropzone` with client-side size/accept validation (`MEDIUM-04`), `Modal` with `openModalStack` + `onCloseRef` + `role="dialog"`/`aria-modal="true"` (`MEDIUM-08`, `LOW-04`), `ToastHost` timer cleanup + `role="status"`/`aria-live="polite"`, and `Toggle` `role="switch"` (`LOW-04`).
  - Fixed `useNotifications.ts` to not abort `loadMore` on `markRead`, removed disabled `purchasing` target, and propagated errors (`MEDIUM-05`).
- **Commerce, Orders, Reports & Mappers (`commerce.tsx`, `orders.tsx`, `reports.tsx`, `mappers.ts`):**
  - Fixed `PriceListDetail` per-row locking (`savingIds`), dirty-checking, functional `setItems`, rollback on error, and `usingMock` input disabling (`CRITICAL-01`).
  - Enabled `brands` lookup in `PromoForm` (`MEDIUM-03`), added `RateModal` base cost validation (`MEDIUM-04`), and eliminated double-fetch page reset `useEffect`s (`MEDIUM-01`).
  - Updated `ReturnsList` to use `adminReturnsApi.stats()`, fixed `CreateReturnModal` item IDs and max qty, added `ReturnDetail.receive` bounds validation, added `PAY_METHODS` fallback and timeline actor metadata (`MEDIUM-01`, `MEDIUM-02`, `MEDIUM-04`, `HIGH-04`, `LOW-01`).
  - Fixed `InventoryReport` KPI label, branch column, Safari date parsing (`replace(' ', 'T')`), and `NotifDrawer` Escape key (`MEDIUM-07`, `LOW-04`).
  - Updated `mappers.ts` to use `toDate()` for Cairo timezone dates and removed unused `optimisticToggle` (`LOW-01`, `INFO-01`).
- **Finance & Wholesale (`finance.tsx`):**
  - Replaced `allPages` transfer filtering with server `treasury_id` (`CRITICAL-02`).
  - Enforced RBAC (`can`) across `FinancePage`, `TreasuriesTab`, `ExpensesTab`, `TransfersTab`, `ShiftsPage`, `ShiftModal`, `WholesalePage`, `QuotesTab`, and `NoticesTab` (`HIGH-02`).
  - Added recovery action for unresolved transfers (`HIGH-05`), fixed `DiffCell`/`ShiftModal` null handling and rounding (`HIGH-06`), clarified page-scoped KPI labels (`HIGH-07`), fixed `NoticesTab`/`QuotesTab` state bleed and note mapping (`MEDIUM-06`), and validated required fields in `ExpenseForm` and `TreasuryForm` (`MEDIUM-04`).
- **Dead Code Removal (`LOW-03`, `INFO-01`):**
  - Removed unused `apps/backoffice/src/pages/supplier/PurchaseOrdersPage.tsx` containing hardcoded mock POs.

### 3. Required Deployment Commands (When Deploying to Server)

1. Pull latest commit and install dependencies if needed.
2. Backend:
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan queue:restart
   ```
3. Frontend (`bags-platform/frontend`):
   ```bash
   pnpm --filter @alfarid/api-client build
   pnpm --filter @alfarid/backoffice build
   ```
4. Environment variables (optional): set `VITE_ATTACHMENT_ALLOWED_HOSTS` in backoffice build env if signed attachment URLs use a custom CDN domain outside `.r2.cloudflarestorage.com`, `.r2.dev`, `.amazonaws.com`, `.digitaloceanspaces.com`, or the API host.
