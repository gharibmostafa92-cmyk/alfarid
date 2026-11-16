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
