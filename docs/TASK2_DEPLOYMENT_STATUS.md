# task2 — Deployment Status

Source roadmaps:
- `_archive_unused/tasks_execution/task2.txt` (Procurement, Supplier Portal, Goods Receipt & Inward Audit, 23 findings — Complete & Verified)
- `task2.txt` (Finance, Concurrency, Report & Treasury Audit, 18 findings — Complete & Verified locally; pending server deployment)

Live Cloud Run revisions and current audit logs: [Task execution audit](TASKS_EXECUTION_AUDIT.md).
Last deployed commit: `25ee590` (see `docs/.last-deployed`)
Date: 2026-11-16
Status: **Ready for Server Deployment**

---

# Deployed vs. Pending Deployment

### A. Already Deployed on Server

- Commit `25ee590` (`2026-11-10`, per `docs/.last-deployed`).
- Live server deployment state unverified from local workspace (no direct production SSH/DB access).
- None of the newly implemented local fixes and migrations from `_archive_unused/tasks_execution/task2.txt` or `task2.txt` are active in production yet.

---

### B. Ready / Pending Server Deployment

All 23 roadmap items from `_archive_unused/tasks_execution/task2.txt` are fully implemented, verified via automated test suites (Backend PHPUnit & Frontend TypeScript / Node.js test runners), and ready for deployment:

#### 1. Database Migrations & Index Hardening
- **`2026_11_16_000003_add_supplier_users_and_procurement_indexes.php`**:
  - `MEDIUM-06`: Adds concurrent unique index on `supplier_users(lower(btrim(email)))` for case-insensitive `O(log N)` authentication and login lookup.
  - `MEDIUM-08`: Adds composite indexes on `purchase_orders(supplier_id, id DESC)` and `supplier_deliveries(supplier_id, id DESC)` for zero-overhead supplier portal dashboard and authorization queries.
  - `MEDIUM-09`: Adds clear descriptive Arabic error messages on `lock_timeout = '3s'` acquisition failures in database migrations.

#### 2. Backend Concurrency, Locking Hierarchy & Business Invariants
- `HIGH-01`: Enforced canonical lock hierarchy `Supplier -> PurchaseOrder -> Deliveries/Items` via `lockSupplierThenOrder` across `cancelPurchaseOrder`, `deletePurchaseOrder`, `closeShort`, `closeDeliveryShort`, `supplierAccept`, and `supplierRequestChange` in `ProcurementService.php`, eliminating PostgreSQL deadlocks (SQLSTATE 40P01).
- `CRITICAL-03`: Implemented canonical items fingerprinting in `ProcurementService::updatePurchaseOrder` (comparing variant IDs, ordered quantities, unit costs, tax rates, and notes) to reject identical payloads as no-ops, preventing deletion/recreation sequence burn and closing the separation-of-duties self-approval laundering vulnerability.
- `HIGH-02`: Realigned `invoiceCeiling` in `SupplierFinanceService.php` to calculate exact 12-decimal `netFactor` without premature intermediate 2-decimal rounding of `discountShare`, maintaining strict 3-way matching precision.
- `MEDIUM-01`: Enforced explicit `is_active` validation on treasuries in `SupplierFinanceService::executePayment` (`if (! $treasury->is_active) throw new BusinessException(...)`).
- `MEDIUM-04`: Enforced `assertBranchAccess($creator, (int) $pr->branch_id)` and verified `$data['branch_id'] === (int) $pr->branch_id` in `ProcurementService::createPurchaseOrder`.
- `MEDIUM-05`: Enforced variant subset matching in `createPurchaseOrder` when explicit items are passed alongside a purchase request.
- `LOW-02`: Added `lastModifier(): BelongsTo` relationship in `PurchaseOrder.php` and exposed `last_modified_by` in `PurchaseOrderResource.php`.

#### 3. Backend Statement Integrity, Performance & Security Boundaries
- `HIGH-03`: `SupplierFinanceService::buildStatementSnapshot` reloads `$supplier = Supplier::query()->findOrFail($supplier->id)` within the transactional snapshot to guarantee mathematical parity between `current_balance` and `closing_balance`.
- `HIGH-04`: Replaced heavy Eloquent model hydration with `toBase()` query builder joins in `buildStatementSnapshot` and enforced a strict 5,000-entry ceiling throwing a clear `BusinessException` on unbounded queries.
- `HIGH-05`: Enforced `assertCentralAccess` on `SupplierController` management actions (`store`, `update`, `destroy`, `users`, `storeUser`, `updateUser`, `destroyUser`), and added row-locking and `is_active` validation during user creation and update.
- `MEDIUM-02`: Prefixed advisory locks in `SerializesAccountEmail` with `'account_email:'` to prevent lock collision with other system advisory locks.
- `MEDIUM-03`: Fixed `SendPurchaseOrderSent` listener to verify `PurchaseOrderStatus::SentToSupplier`, load fresh `$po->supplier_id`, pass the updated `$po` instance, and isolate recipient notification dispatching.

#### 4. Frontend Storefront (Supplier Portal)
- `CRITICAL-01`: Created dynamic detail pages:
  - `apps/storefront/src/app/supplier/purchase-orders/[id]/page.tsx`: Full purchase order details, item breakdown, accept, request change, and ASN delivery notice submission.
  - `apps/storefront/src/app/supplier/deliveries/[id]/page.tsx`: Shipment notice tracking, delivery line items, status details, and protected attachment downloads.
- `CRITICAL-02`: Restored all Arabic text labels, titles, loading indicators, and logout buttons across `layout.tsx`, `page.tsx`, and `profile/page.tsx`, and added `dir="rtl"` to main authenticated containers.
- `MEDIUM-07`: Fixed statement filter in `storefront/src/app/supplier/statement/page.tsx` to clear previous errors upon form submission or reset.

#### 5. Frontend Backoffice (Operations Procurement)
- `HIGH-06`: In `suppliers.tsx`:
  - `POModule::openForm`: Eager-loads full purchase order details via `purchaseOrdersApi.show(po.id)` when editing from list view if items are missing.
  - `ReceivePOModal`: Automatically loads deliveries if not populated on the input purchase order object.
  - `PurchasingPage`: Clears `paymentInvoice` upon form open via `onClearInitialInvoice` callback, allowing re-payments without UI lockup.
- `HIGH-07`: In `ReceivePOModal`: Added `useEffect` on `[open, po?.id]` to reset `qtys`, `deliveryId`, `locationId`, and `lastPayload.current`, and generate fresh `newIdempotencyKey()`, preventing idempotency collision and silent data loss across consecutive receipts.
- `LOW-01`: Replaced `setForm(false)` with `setForm(null)` across `POModule`, and scoped `approvedPRs` to `branch_id: form?.branch — Number(form.branch) : undefined`.
- `LOW-02`: Added user separation check in `POModule::actionsFor` to prevent the creator or last modifier from self-approving purchase orders.
- `MEDIUM-07`: Added `from_date` and `to_date` date pickers in `SupplierDetail` statement tab, passing date bounds to `suppliersApi.statement(id, { from_date, to_date })`.
- `MEDIUM-10`: In `PayModule::save`: Added client-side validation ensuring `form.sup`, `form.treasury`, and positive `form.amount` are present, requiring reference number for non-cash payment methods, and auto-filling remaining invoice balance when selecting an invoice.

#### 6. Repomix Synchronization
- Updated `repomix/configs/repomix.subsystem-08-procurement-suppliers.json` to include newly added storefront dynamic routes and backend resources.
- Synchronized bundles in `repomix/outputs/started/repomix-subsystem-08-procurement-suppliers.xml` and `repomix/outputs/repomix-subsystem-08-procurement-suppliers.xml`.

---

## Required Deployment Commands & Operational Steps

When authorized for staging/production deployment:

1. **Database Migration**:
   ```bash
   php artisan migrate --force
   ```
2. **Schema & Index Verification**:
   ```bash
   php artisan db:verify-schema
   ```
3. **Cache Invalidation & Warmup**:
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```
4. **Queue Worker Restart**:
   ```bash
   php artisan queue:restart
   ```
5. **Frontend Asset Build**:
   ```bash
   pnpm -r build
   ```

---

## Automated Verification Evidence

- **Backend Feature Test Suites**:
  - `Task2Slice1ProcurementIntegrityTest.php`: 6/6 tests passed (14 assertions).
  - `Task2Slice2ProcurementSecurityAndStatementTest.php`: 4/4 tests passed (9 assertions).
  - `ProcurementTest.php`: 12/12 tests passed (96 assertions).
  - `TaskTxtProcurementCloseoutTest.php`: 11/11 tests passed (58 assertions).
  - Total: **33/33 tests passed, 177 assertions, 0 failures, 0 errors**.
- **Frontend Test Suite & Static Analysis**:
  - `node bags-platform/frontend/tests/operations-suppliers.test.cjs`: 5/5 tests passed.
  - Storefront TypeScript Typecheck (`apps/storefront/src`): 0 errors (`tsc --noEmit`).
  - Backoffice TypeScript Typecheck (`apps/backoffice/src`): 0 errors (`tsc --noEmit`).

---

## 2026-11-17 Update — Storefront, Catalog, Coupons & Shipping Hardening (`F-01`–`F-19`)

All 19 items in `_archive_unused/tasks_execution/task2.txt` (`F-01` through `F-19`) are implemented and verified locally, and remain **Pending Server Deployment** (live server deployment unverified from local workspace):

- **Backend Catalog, Banners & RBAC (`F-01`, `F-04`, `F-05`, `F-06`, `F-08`, `F-09`, `F-14`, `F-16`, `F-17`, `F-18`)**:
  - `BannerController` implements `HasMiddleware` (`permission:store.manage|settings.view` for read, `permission:store.manage` for write) + `StoreBannerRequest::authorize()` enforces `store.manage`, `mimes:...,avif`, and `ends_at` validation against existing `starts_at` on updates; `BannerController::index` caps unbounded reads at 200 (or `per_page`).
  - `StoreCatalogService::HOME_BANNERS_CACHE_KEY` (`store:home:banners:v1`) is cleared in `forgetStorefrontCaches()`, and `HomeController::activeBanners()` uses `Cache::remember(..., 60, ...)` so scheduled/edited banners reflect immediately.
  - `RetailPriceSnapshot::refresh` serializes 200-row chunks with `ORDER BY id FOR NO KEY UPDATE`.
  - `ProductController::destroy` forwards `expected_version` to `ProductService::delete`; `ProductController::index` uses uppercase indexed `sku`/`barcode` lookups; `ReorderCategoriesRequest` validates `items.*.expected_version`.
  - `StoreCatalogService::cachedFacetsPart` and `ProductMediaService::withMediaLock` reduce lock TTL / wait bounds (`block(2)` / `block(3)`).
  - `BarcodeGenerator::fromId` enforces 11-digit bounds (`1..99_999_999_999`); `ProductService::create` and `VariantService::create` use `updateQuietly` for derived codes/barcodes.
  - `F-14` Arabic validation messages verified intact across all 5 request/concern classes.
- **Backend Coupons & Indexes (`F-02`, `F-13`)**:
  - `CouponService::recordUsage` rejects anonymous redemptions and acquires sorted deterministic `pg_advisory_xact_lock`s on both `customer_id` and normalized phone; `CouponController::update` catches `UniqueConstraintViolationException` as 422; `CouponRequest::after()` rejects `usage_limit < used_count`.
  - Verified `coupon_usages_coupon_id_foreign` (`ON DELETE RESTRICT`) and `products_occasions_gin` GIN index in schema.
- **Backend Shipping & Webhooks (`F-07`, `F-10`, `F-11`, `F-12`)**:
  - `ShippingQuoteService::quoteForCity` rejects inactive/missing cities when no rate matches; `ShippingRate` and `ShipmentService` use `BigDecimal` for free-shipping thresholds and preserve `0.00` for `free_shipping` coupons.
  - `Shipment::trackingUrl()` enforces `https://` scheme; `ShippingWebhookService` locks `orders -> shipments` in global order and checks `hasNewerDeliveredOrActiveShipment` on `Returned`; `ShippingZoneController::destroy` and `ShippingCompanyController::destroy` use `DB::transaction` + `lockForUpdate()` + SQLSTATE `23503` handling.
- **Frontend Storefront & API Client (`F-03`, `F-06`, `F-08`, `F-15`, `F-19`)**:
  - `store-image.ts` restricts `/store-media/` fallback to `*.r2.dev` / `*.r2.cloudflarestorage.com` and strips `url.search`.
  - `catalog.ts` passes `expected_version` in `productsApi.remove`, sets `timeout: 300_000` on `uploadVideo`, and guards/clamps `onUploadProgress`.
  - `cart-store.ts` queues `addItem` via `run(...)`, surfaces Arabic errors on stock guard failure, and unifies error messages to Arabic.
  - **Deployment action**: Run `pnpm -r build` for frontend assets (`storefront`, `backoffice`, `api-client`) alongside backend deploy & `php artisan queue:restart`.

---

## 2026-11-18 Update — Finance, Concurrency, Reports & Treasury (`task2.txt`)

All 18 findings from the repository root `task2.txt` were audited against the current implementation. Every item is verified locally; the work below remains **Pending Server Deployment** (live deployment is unverified from this workspace).

### Newly completed in this pass

- **HIGH-04 — Migration driver guards & schema verification**: added `DB::getDriverName() !== 'pgsql'` guards to `2026_11_12_000025`, `000027`, `000032`, `000041`, `000042`, `000064`, `000068` and `2026_11_13_000001`; wrapped `VALIDATE CONSTRAINT` inside a `lock_timeout` transaction in `000095`; `VerifyDatabaseSchema` already validates `treasuries_nonnegative_cash`, `treasury_transactions_reference_unique`, `treasury_transactions_opening_balance_unique`, `wallet_adjustments_one_reversal_idx`, `pos_shifts_one_open_user`, `pos_shifts_one_unsettled_drawer` and `wallet_transactions_reference_alias_unique`.
- **MEDIUM-05 — Finance reconcile scalability**: `finance_reconcile_runs(created_at)` is indexed and `finance:reconcile` now exposes `--wallet-chunk` (default 50,000 customers). Treasury and wallet audits run in separate read-only snapshots; wallets are scanned in bounded id ranges so one large ledger cannot abort the treasury audit.
- **MEDIUM-06 — FinancialDashboard theme toggle**: `changeTheme(true)` while already dark no longer flips to light. Resolved through the pure `resolveDarkMode(targetDark)` helper in `initial-state.js`; currency symbols and masked card placeholders were already corrected.

### Already implemented and re-verified

- CRITICAL-01/02/03, HIGH-01/02/03/05, MEDIUM-01/02/03/04/07, LOW-01/02, INFO-01 (see the verification log at the end of `task2.txt`).

### Required deployment actions

- **Database**: `php artisan migrate --force` (the guard/`lock_timeout` edits only alter behaviour for fresh or non-PostgreSQL environments; no schema change is applied to an already-migrated production database).
- **Schema verification**: `php artisan db:verify-schema` must exit successfully; it now fails if any ledger guard index/constraint is missing.
- **Application cache**: `php artisan config:cache && php artisan route:cache && php artisan view:cache`.
- **Queue workers**: `php artisan queue:restart` so scheduled reconcile jobs load the updated command.
- **Frontend assets**: rebuild the UI package consumers (`pnpm -r build`, at minimum `@alfarid/ui`, backoffice and storefront) so the dashboard theme fix ships.
- **Environment variables**: none new. Existing reconcile scheduler configuration is unchanged; the new `--wallet-chunk` option defaults to 50,000.

### Automated verification evidence

- Backend targeted run: **45 passed / 216 assertions** (`Task2Slice1SchemaTest`, `Task2Slice2ConcurrencyAndLocksTest`, `Task2Slice2SecurityTest`, `Task2Slice3ReportsAndAiTest`, `Task2Slice4SecurityTest`, `Task2MigrationDriverGuardsTest`, `Task2ReconcileFinanceChunkingTest`, `Task8Slice4ReportsAndReconcileTest`, `FinanceIntegrityTest`, `AdminReportsTest`).
- Ledger/AI/store targeted run: **35 passed / 203 assertions** (`WalletPageReadTest`, `FinanceExpenseToolsTest`, `FinanceTransferToolsTest`, `FinanceBriefingTest`, `FinanceWalletTest`, `WalletLedgerGuardTest`).
- Frontend: `operations-finance.test.cjs` + `financial-dashboard.test.cjs` — **11 passed, 0 failed**.

---

## 2026-11-18 Update (Pass 2) — AI Wholesale, Pricing & Finance Tools Hardening (`_archive_unused/tasks_execution/task2.txt`)

All 20 findings from `_archive_unused/tasks_execution/task2.txt` (`CRITICAL-01`–`02`, `HIGH-01`–`08`, `MEDIUM-01`–`08`, `LOW-01`–`02`) are implemented and verified locally, and remain **Pending Server Deployment** (live deployment is unverified from this workspace):

- **Cross-Branch Authorization & Prompt Consistency (`CRITICAL-01`, `HIGH-04`)**:
  - Added `WholesaleAccessGuard` and enforced branch/central-access & treasury checks across `ApprovePaymentNoticeTool`, `RejectPaymentNoticeTool`, `ReversePaymentNoticeApprovalTool`, `BulkApproveMatchedNoticesTool`, `GetPaymentNoticeTool`, `WholesaleInboxTool`, `PriceQuotationTool`, `BulkPriceQuotationsTool`, and `CreateExpensesBulkTool`.
  - Updated `WholesaleToolProvider::systemPromptSection` to reflect write-proposal confirmation when `ai_agent.execute` is granted vs read-only otherwise.
- **ToolRegistry, Locks, Undo & Concurrency (`HIGH-01`, `HIGH-02`, `HIGH-05`, `HIGH-06`, `HIGH-07`, `LOW-01`)**:
  - Enforced optimistic version checks for `'bulk_child'` actions in `ToolRegistry::lockTargets` and `PricingLocks`; fixed numeric string `lock_version` comparison in `ToolRegistry::checkVersion`; locked and re-read roles/users in `execUpdateRolePerms` / `execAssignRoles` / `execSetUserStatus` (rechecking `mayGrantPermissions` and `hasOtherActiveSuperAdmin` under lock); cleared `ProposalBuilder::$conversation` in `finally` inside `ToolRegistry::dispatchAllowed`.
  - Implemented `LocksTargets` and row-level locking on `BulkApproveMatchedNoticesTool`, `BulkPriceQuotationsTool`, `ExpireQuotationTool`, `ExtendQuotationValidityTool`, and cryptographic notice fingerprinting (`WholesaleAccessGuard::noticeFingerprint`).
  - Enforced `lockForUpdate()` and `branchMoveBlockers` recheck in `UpdateTreasuryTool::undo`, row locks in `SetTreasuryStatusTool::undo`, `PriceQuotationTool::undo`, `DeletePriceListItemsTool::undo`, and `UpsertPriceListItemsTool::undo`.
  - Locked customer row before checking `wallet_balance` in `AdjustCustomerWalletTool::undo`, added `customers.view` check in `resolveCustomer`, and included formatted `wallet_balance` in `walletFingerprint` across `AdjustCustomerWalletTool` and `ReverseWalletAdjustmentTool`.
- **Pricing Read/Write Safety & Business Logic (`CRITICAL-02`, `HIGH-03`, `MEDIUM-01`, `MEDIUM-02`, `MEDIUM-04`, `MEDIUM-07`)**:
  - Removed DB transactions, `lockForUpdate()`, and `price_list_items` updates from `WhatIfPriceChangeTool`; added in-memory `price_overrides` support to `PricingService::pickWinningItem`.
  - Guarded missing/non-positive `list_price` (and `QuoteAdvisor::suggest` `BusinessException`) in `BulkPriceQuotationsTool`, missing/zero `min_qty = 1.0` tier in `QuotePriceTool`, null aggregates in `CouponPerformanceTool`, numeric SKU preservation & quantity parsing in `CreateQuotationForCustomerTool`, `Arr::only` whitelists + `buy_x_get_y` guard in Pricing create/update tools, `ReconcileBankStatementTool` table structure + `unset($row)`, `PricingHealthReportTool` Retail exclusion & drift rounding, and `GetQuotationTool` nullable `cost_price`.
- **AI Gateway Security, Parsing & Privacy (`MEDIUM-03`, `MEDIUM-05`, `MEDIUM-08`, `LOW-02`)**:
  - Added atomic `consumed_at` claim before Gemini Vision calls (with rollback on failure) in `ParseReceiptTool` and `ParsePriceSheetTool`, plus `ToolOutcome::untrusted` wrapping for vision `source_row`.
  - Expanded `FinancePresenter::MONEY_KEYS` and redacted numeric shortfalls in `suggestTopUp` `prompt` when `ai_finance_share_balances_with_model` is disabled.
  - Added finance/pricing/wholesale entities to `AiMessageController::validated`, replaced `Paginator::currentPageResolver` mutation in `GetPriceListTool` with explicit `page` in `PriceListService`, avoided double `getContent()` reads in `AiAttachmentController`, capped `CreateExpensesBulkTool` CSV length before `preg_split`, validated `from`/`to` in `CustomerStatementTool`, and returned `$converted` in `FinanceArgs` / `PricingArgs`.
- **Performance & N+1 Elimination (`HIGH-08`, `MEDIUM-06`)**:
  - Moved `AgingReportTool` to SQL `GROUP BY` + `LIMIT 50`; added 120s cache, `contextFor($customer, $variantIds, $branchId)`, and pre-sorted coupons in `MarginRiskReportTool`.
  - Batched `CustomerAccount360Tool` payment-notice queries, replaced `whereNotIn` with `whereNotExists` & batched `roundRepeated` in `ExpenseAnomaliesTool`, removed `$list->fresh()` inside loops in `UpsertPriceListItemsTool` and `ClonePriceListTool`, and batched product eager-loading in `SimulateCartTool`.

### Required deployment actions (Pass 2)

- **Database**: No new migrations in this pass.
- **Application cache & Queue workers**: `php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan queue:restart`.
- **Environment variables**: None new.

### Automated verification evidence (Pass 2)

- `Task2ExecutionSlice1AuthAndPromptTest.php`: 3 passed, 20 assertions.
- `Task2ExecutionSlice2LocksAndUndoTest.php`: 6 passed, 24 assertions.
- `Task2ExecutionSlice3PricingAndLogicTest.php`: 6 passed, 21 assertions.
- `Task2ExecutionSlice4GatewayAndPrivacyTest.php`: 5 passed, 22 assertions.
- `Task2ExecutionSlice5PerformanceTest.php`: 4 passed, 13 assertions.
