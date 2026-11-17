# Task 6 (Wholesale Portal & B2B Hardening) — Deployment Status

Source roadmap: `_archive_unused/tasks_execution/task6.txt` (24 findings: F01–F24).  
Last verified deployed commit: `25ee59019f51c883e5c44b9955df155f2f6851ca` (see `docs/.last-deployed`).  
Date: 2026-11-16  
Live server access: **Unverified** (local workspace verification only; do not assume any local change is deployed).

---

# Deployed vs. Pending Deployment

## A. Already Deployed on Server

- Commit `25ee59019f51c883e5c44b9955df155f2f6851ca` (baseline prior to local audit fixes).
- **None of the Task 6 Wholesale (F01–F24) fixes below are deployed to production yet.**

## B. Ready / Pending Server Deployment

### 1. Backend Code Changes

- ✅ **F02** — `WholesaleRegistrationService::purgePathsAndDirectory()` passes `$customerDirectory` instead of undefined `$stagingDirectory`, preventing `ErrorException`/`TypeError` on rollback and ensuring R2 KYC cleanup.
- ✅ **F03** — `RejectExecutableSignature` adds `bool $allowOfficeOpenXml = false` with `ZipArchive` validation (`[Content_Types].xml`, `xl/workbook.xml`, 20 MB uncompressed cap, no `vbaProject.bin`, no `../` Zip Slip); enabled in `QuickOrderController::import()`.
- ✅ **F04** — `WholesalePaymentNoticeService` (`orderCoveredAmount`, `allocatedTotal`, `allocatedOpenTotal`, `previewNoticeAllocation`), `WholesaleInsights::coveredByOrderIds()`, and `WholesaleDailyBriefing` filter `wholesale_payment_allocations` by parent notice `status = 'approved'`.
- ✅ **F05** — `WholesalePaymentNoticeService::releaseExcessAllocationsForCreditNote()` and `settleCreditNote()` wrapped in `DB::transaction()` with deterministic `Customer -> Order -> Notice/Allocation` `lockForUpdate()` ordering.
- ✅ **F06** — `WholesalePaymentNoticeService::statement()` and `creditNoteAmountForOrder()` restrict `ReturnRequest` credit notes to non-cancelled credit orders (`payment_method = 'credit'`, `status != 'cancelled'`).
- ✅ **F07** — `QuotationService::price()` requires positive `offered_unit_price > 0` across all quotation items; `accept()` re-verifies `wholesalePurchasable()` and positive prices.
- ✅ **F08** — `WholesalePaymentNoticeService::reverse()` verifies active treasury, dispatches `PaymentNoticeReviewed` via `DB::afterCommit()`, and `WholesaleLifecycleSubscriber::onNoticeReviewed()` handles `'reversed'` status distinctly.
- ✅ **F09** — `CustomerWholesaleAssistant` wires `[سلة]` / `[عرض سعر]` buttons via 15-minute customer-scoped cache tokens (`importToCart` / `quotations->create + submit`); `customer_statement` template added via migration.
- ✅ **F10** — `ScoreWholesaleReceipt::__construct()` sets `$this->afterCommit()`; `QuotationService::accept()` dispatches `OrderCreated` inside `DB::afterCommit()`.
- ✅ **F11** — `Wholesale\InvoiceController` (`index`, `pdf`) and `Wholesale\DashboardController::index()` share an invoiceable scope excluding `cancelled` and `draft` orders.
- ✅ **F12 (Backend)** — `Wholesale\ProductController::exportPriceList()` caps synchronous DomPDF export at 500 items with 5-minute customer-scoped caching; `Wholesale\OrderController::index()` supports `returnable=1` and `per_page=100`.
- ✅ **F13 & F14 (Backend)** — `QuotationService::price()` allows re-pricing `PricedByAdmin` quotations (incrementing `revision`); `Wholesale\QuotationController::accept()` and `QuotationService::accept()` enforce optional `expected_revision` (returning HTTP `409` on conflict).
- ✅ **F15** — `StorePaymentNoticeRequest`, `ExpireQuotations`, `MarkWholesaleOverdue`, `WholesaleDailyBriefing`, and `QuotationService` use `config('app.business_timezone', 'Africa/Cairo')`.
- ✅ **F16 (Backend)** — `WholesaleQuickOrderService::findVariants()`, `lookup()`, and `importToCart()` match `UPPER(sku)` and `barcode` case-insensitively.
- ✅ **F19 & F23** — `PriceListService` invalidates `PricingService::flushCatalogContexts()` and updates `customer->updated_at` in `DB::afterCommit()` for `CustomerSpecific` lists; `exportCsv()` removes `X-Row-Count` (`COUNT(*)` query); `importCsv()` logs summary + SHA-256 hash when importing > 100 rows.
- ✅ **F20** — `BankStatementMatcher::cleanAmount()` rejects negative and parenthesized amounts as `'0.00'`, normalizes European comma decimals, and `match()` skips `<= 0.00` rows.
- ✅ **F21** — `WholesaleInsights::creditSuggestions()` filters `allocatedByCustomer` by `n.status = 'approved'` and ranks customers by open-credit utilization descending before `limit(100)`.

### 2. Database Migrations (Not Yet Applied to Production)

1. `bags-platform/backend/database/migrations/2026_11_16_000002_add_customer_statement_message_template.php`
   - Idempotently inserts/updates `customer_statement` (`language = 'ar'`, `status = 'approved'`) in `message_templates`.
   - Deploy command: `php artisan migrate --force`

### 3. Frontend Code Changes (Rebuild Required)

- ✅ **F01** — `finance-screens.jsx` (`Notifications`): fixed undefined `all` `ReferenceError`; uses `meta.total` in `useRef` and `unreadCount`.
- ✅ **F12 (Frontend)** — `orders-screens.jsx` (`ReturnForm`) and `finance-screens.jsx` (`NoticeForm`) replace unbounded `allPages` loops with capped `per_page: 100` requests (`returnable: 1` for returns).
- ✅ **F14 (Frontend)** — `api-client/src/endpoints/wholesale.ts`, `mappers.js`, and `orders-screens.jsx` (`QuoteDetail`) send `expected_revision` on `acceptQuote`.
- ✅ **F17** — `orders-screens.jsx` (`ReturnForm::addFiles`) moves `validateReturnFiles`, `setErrs`, and `toast` outside `setSel` updater.
- ✅ **F18** — `WholesalePortal.jsx` (`addToCart`) and `buy-screens.jsx` (`VariantModal`) await `onAdd` with `busy` state and only close modal on success.
- ✅ **F22** — `auth-screens.jsx` (`AuthFrame`): removed dead `English` button (`viewBox="0 0 24 24"` already valid).
- ✅ **F24** — Removed 9 orphan dummy `return null;` pages under `bags-platform/frontend/src/app/wholesale/**`.

### 4. Environment, Queue, Scheduler & Build Actions

- **Environment variables:** None added or modified.
- **Queue / Scheduler:** `ScoreWholesaleReceipt` now enforces `afterCommit`; `wholesale:monthly-statements` requires the `customer_statement` migration above.
- **Build commands:**
  - Backend: `php artisan migrate --force && php artisan config:cache && php artisan route:cache`
  - Frontend: `cd bags-platform/frontend && pnpm build`

---

## Round 2 Hardening (`_archive_unused/tasks_execution/task2.txt`, F-01..F-20) — Pending Server Deployment

All 20 Round 2 Wholesale & Finance hardening items from `_archive_unused/tasks_execution/task2.txt` are implemented and verified locally (not yet deployed to production):

### 1. Backend Code Changes
- ✅ **F-01** — `PreviewNoticeAllocationTool`: enforces `assertCentralAccess` for free notices and `assertBranch` for order-linked notices, passing `$branchId` to `previewNoticeAllocation()`.
- ✅ **F-02** — `CustomerWalletController`: enforces `BranchAccess::assertCentralAccess` across all 5 endpoints (`show`, `summary`, `adjustments`, `storeAdjustment`, `reverseAdjustment`) and requires non-empty `Idempotency-Key` on `storeAdjustment`.
- ✅ **F-03** — `Wholesale\ProductController::index()`: uses recursive CTE over `categories` (`is_active = true AND deleted_at IS NULL`) to exclude products under inactive or soft-deleted ancestors at any depth.
- ✅ **F-04 & F-09** — `OrderService::changeStatus()` locks `Customer` before `Order` on credit orders; `BankStatementMatcher` normalizes Persian/Urdu digits (`۰-۹`) and `ٱ` plus missing Arabic bank aliases (`بنك الإسكندرية`, `بنك قطر الوطني`, `البنك العربي الأفريقي`); `WholesalePaymentNoticeService::receiptCollisions()` flags cross-customer reference collisions regardless of amount/date differences.
- ✅ **F-05** — Verified already addressed by `2026_11_16_000004_scope_credit_note_coverage_to_credit_orders.php`.
- ✅ **F-06** — `QuotationVersion::hash()` includes `revision`, `discount_amount`, `tax_amount`, `grand_total`, `valid_until`; `QuotationService::restorePricedSnapshot()` preserves original `status` and increments `revision`.
- ✅ **F-07** — `CustomerWholesaleAssistant::reorder()` and `orderStatus()` exclude `cancelled` and `draft` orders; `reorder()` stages confirmation via `wa:order:{customerId}:{token}` instead of mutating the live cart.
- ✅ **F-08** — `QuickOrderController::import()` removes `.xls` (allows `xlsx,csv,txt`) and caps `maxOoxmlBytes` at 5 MB; `WholesaleQuickOrderImport` enables `setReadDataOnly(true)` and safe CSV settings.
- ✅ **F-10** — `WholesalePaymentNoticeService::statement()` orders same-reference entries by causal timestamp; `recentStatementCount()` counts both approval and reversal entries for reversed notices in window.
- ✅ **F-11** — `WholesalePaymentNoticeService::outstandingBalanceDecimal()` made `public` and used directly in `QuotationService`; `QuoteAdvisor::suggest()` warns when `credit_limit <= 0`.
- ✅ **F-12** — `WholesalePaymentNoticeService::approve()` detaches cancelled order links (`order_id = null` with audit note) and allows approving advance floating credit.
- ✅ **F-13 & F-20** — `PriceListService::applyPercentage()` omits full `$before` item dump when `> 100` items; `createItem`/`updateItem`/`deleteItem` dispatch `RefreshCatalogRetailPrices` for retail lists; `PriceListService` and `CustomerGroupService` clamp `per_page` to `1..100`.
- ✅ **F-14** — `PriceListService::csvText()` and `WholesalePriceListExport` escape leading `|`; `WholesalePriceListExport` extends `StringValueBinder` (`WithCustomValueBinder`).
- ✅ **F-15** — `BankStatementMatcher::match()` pre-filters descriptions with `str_contains()` before `preg_match()` and restricts customer cursor to `approved`.
- ✅ **F-16** — `QuoteExpiryReminders`, `WholesaleDailyBriefing` (`updated_at`), and `WholesaleInsights` standardize on `app.business_timezone` (`Africa/Cairo`) and add deterministic ordering before `limit(100)` in `reorderOutlook()`.
- ✅ **F-18** — `WholesaleQuickOrderService::findVariants()` separates `by_sku` and `by_barcode` maps.
- ✅ **F-19** — `WholesaleRegistrationService::register()` uses `withTrashed()` for upfront email/phone uniqueness checks before R2 uploads.

### 2. New Database Migration (Not Yet Applied to Production)
- `bags-platform/backend/database/migrations/2026_11_17_000002_harden_wholesale_lock_order_and_bank_identity.php`:
  - Updates `lock_wholesale_allocation_parents()` trigger to lock notices `ORDER BY n.id FOR NO KEY UPDATE` on order reopen.
  - Updates `wholesale_reference_identity` & `wholesale_bank_identity` and rebuilds `wholesale_notices_approved_reference_unique` `CONCURRENTLY`.
  - Deploy command: `php artisan migrate --force`

### 3. Frontend Code Changes (`F-17`)
- `WholesalePortal.jsx`: stops notification polling on `401`/`403`, clears cart/navigates on fallback `logout`, wraps screens in `PortalErrorBoundary`.
- `buy-screens.jsx` & `packages/api-client/src/endpoints/wholesale.ts`: adds `AbortController` + sequence guard to `QuickOrder::lookup()`.
- `finance-screens.jsx`: loads `per_page: 100` unpaid invoices in `Statement` and displays server `debt` in the open-dues total.
- `auth-screens.jsx`: attaches `document_type` when re-uploading rejected KYC documents.
- Build command: `cd bags-platform/frontend && pnpm build`
