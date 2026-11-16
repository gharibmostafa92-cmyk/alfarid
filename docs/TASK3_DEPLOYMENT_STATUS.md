# Task3 — Wholesale Portal, Financial Invariants, Pricing & Concurrency Roadmap

Source roadmap: `_archive_unused/tasks_execution/task3.txt` (21 roadmap items: 4 Critical, 8 High, 7 Medium, 1 Low).
Date of Execution: 2026-11-15
Branch: `fixes/task-audit-roadmap`
Status: 100% Implemented & Verified in local repository (`_archive_unused/tasks_execution/task3.txt` all marked `✅`).

---

# Deployed vs. Pending Deployment

## A. Already Deployed on Server

> [!IMPORTANT]
> Live server deployment status is **unverified / not deployed**. All 21 roadmap findings below have been implemented, tested with strict TDD (RED → GREEN → REFACTOR), and verified locally. None of these changes have been pushed or deployed to the production environment.

- **Current Live Server Baseline Commit:** `25ee590` (refer to `docs/.last-deployed`)
- **Confirmed Live Code:** Pre-task3 wholesale codebase.
- **Active Production Migrations:** Applied up to migration `2026_11_15_000005_*`. Migrations `2026_11_15_000006_*` and `2026_11_15_000007_*` are NOT yet applied to production.
- **Server Services:** Queue workers and web servers are currently running pre-task3 worker code.

---

## B. Ready / Pending Server Deployment

The following 21 roadmap items are fully implemented, regression tested, and ready for deployment:

### 1. Slice 1: Finance & Ledger Integrity
- **CRITICAL-02:** `WholesalePaymentNoticeService::balanceBreakdown()` Ledger Invariant
  - Unified mathematical ledger invariant: `Net Outstanding = ∑Credit Orders (non-cancelled) - ∑Approved Notices - ∑Refunded Credit Notes (on credit non-cancelled orders)`.
  - Added safe credit note handling for paid orders without double-counting floating credit.
  - Matches 100% with `statement()` closing balance.
- **CRITICAL-04:** `releaseExcessAllocationsForCreditNote()` & `assertCreditNoteCapacity()`
  - Accepts `$excludeReturnRequestId` to prevent double-counting refunded credit note amounts during transaction commit.
  - Fixed order of operations in `ReturnService::processRefund` so allocations are released before the credit note is marked `refunded`.
- **HIGH-01:** `previewNoticeAllocation()` Read-Only Notice Allocation Preview
  - Replaced heavy and destructive transaction simulation with a non-mutating preview.
  - Recomputed remaining amounts on linked orders taking credit notes and existing approved notices into account.
- **MEDIUM-02:** `QuotationService::accept()` Credit & Address Enforcement
  - Added strict customer credit limit check before allowing quotation acceptance.
  - Validates that shipping address exists and belongs to the customer before transitioning to order.

### 2. Slice 2: Security Boundaries, Category Hierarchy & Branch Isolation
- **CRITICAL-01:** `ProductController::index` Category Ancestor Filtering
  - Replaced ambiguous/reversed `orWhereHas` with scoped `whereDoesntHave('parent', fn ($p) => $p->where('is_active', false)->orWhereHas('parent', ...))` ensuring active category hierarchy up to 3 levels.
  - Prevents inactive parent/grandparent categories from leaking products to the wholesale catalog.
- **CRITICAL-03:** Branch Access Control on Notices & Reverse Endpoint
  - Eager-loaded `order` relation in `approve()`, `reject()`, and `reverse()` to prevent `LazyLoadingViolationException` under `Model::preventLazyLoading(true)`.
  - Registered `POST /api/v1/admin/payment-notices/{payment_notice}/reverse` endpoint protected by `can:payment_notices.approve`.
  - Added `assertTreasury` permission check inside `WholesalePaymentNoticeService::reverse()`.
  - Enforced branch approver scoping in `approve()`, passing `$restrictedBranchId` to `settleFreeNoticeFifo()` to prevent branch approvers from settling orders of other branches.
- **MEDIUM-01:** `RejectExecutableSignature` Magic Bytes Enforcement
  - Inspects 8-byte file header to strictly reject Windows PE (`MZ`), Linux ELF (`\x7FELF`), macOS Mach-O (32-bit, 64-bit, FAT), PHP tags (`<?`), and Shebang (`#!`).
- **MEDIUM-05:** `WholesaleRegistrationService` Direct S3/R2 Uploads
  - Pre-allocates sequence `$customerId` upfront and uploads documents directly to `customer_documents/{$customerId}/` in a single pass, eliminating intermediate staging and expensive storage copy/move calls.

### 3. Slice 3: Catalog Pricing, Availability & Import DOS Protection
- **HIGH-02:** Unified Branch Stock Availability Query
  - Unified stock calculation across `WholesaleQuickOrderService`, `WholesalePriceListService`, `QuotationService`, and `QuoteAdvisor`.
  - Inner joins `branches` and filters `where('branches.is_active', true)`.
  - Aliased aggregate as `total_available` to prevent Eloquent accessor shadowing by `StockLevel::getAvailableAttribute()`.
- **HIGH-03:** Catalog Retail Price Cache Invalidation
  - Dispatches `RefreshCatalogRetailPrices::dispatch($productIds)` inside `DB::afterCommit` in `PriceListService::update()` whenever price list status, branch, or priority headers are modified.
  - Added `where('is_active', true)` for customer-specific price lists and implemented `PricingService::flushCatalogContexts()`.
- **HIGH-05:** Excel Quick Order Import Memory & Column DOS Protection
  - Implemented `WithMultipleSheets` (returning sheet 0 only) and `WithColumnLimit('D')` in `WholesaleQuickOrderImport`.

### 4. Slice 4: Reconciliation, Statement Dual Entries & Spoken Arabic
- **HIGH-04:** `WholesalePaymentNoticeService::statement()` Append-Only Ledger Semantics
  - Pinned statement dates and defaults to `config('app.business_timezone', 'Africa/Cairo')`.
  - Included reversed notices as dual entries (credit on approval date, debit on reversal date with reason) to preserve strict append-only ledger accounting without retrospective alteration of closed statements.
  - Opening balance accurately accounts for active vs reversed notices across temporal boundaries.
- **HIGH-08:** `ScoreWholesaleReceipt` Gemini Structured Output Unpacking
  - Unpacks `$read['data']` payload returned by `GeminiClient::generateStructured()`, preventing 0.0 receipt match scores.
- **MEDIUM-03:** `WholesalePaymentReminders` Weekly Cap SQL Subquery
  - Filters out customers who have already reached their weekly reminder cap directly in the SQL query, preventing order starvation and eliminating N+1 queries.
- **MEDIUM-04:** `BankStatementMatcher` Normalization & Collision Prevention
  - Added `cleanAmount()` converting Eastern Arabic numerals and sanitizing thousand separators via `BigDecimal`.
  - Added `cleanReference()` mirroring PostgreSQL `wholesale_reference_identity` rules.
  - Disqualifies probable matches when statement and notice have explicit, conflicting references.
  - Requires customer company names to have `>= 4` characters for statement description matching.
- **MEDIUM-07 (SpokenQuantity):** Spoken Arabic Number Parsing
  - Added compound hundreds (`300` through `900`), dual forms (`دستتين` = 24, `الفين` = 2000), and dozen multipliers.

### 5. Slice 5: Frontend Experience, Contracts & Edge Cases
- **HIGH-06:** Reversed Notice Status Mapping
  - Added `reversed: ['معكوس', 'danger']` to `NOTICE_STATUS` in `constants.js`.
  - Added informative reversal explanation banner in `NoticeDetail` (`finance-screens.jsx`).
  - Added filter options (`all`, `pending`, `approved`, `rejected`, `reversed`) in `Notices` screen.
- **HIGH-07:** `WholesaleQuickOrderService::addLinesToCart` Cart Row Locking
  - Wrapped cart line additions in `DB::transaction()` with `Cart::query()->whereKey($cart->id)->lockForUpdate()->first()`, eliminating concurrency races on cart line quantities.
- **MEDIUM-06:** `WholesalePortal.jsx` Robustness
  - Handled partial stock skips in `addToCart`: shows warning alert when lines are skipped due to insufficient stock instead of false success toast.
  - Safe navigation on cart lines total: `cart?.lines?.reduce((s, l) => s + (l?.qty ?? 0), 0) ?? 0`.
  - Guarded 403 response interceptor with `err.code === 'wholesale_not_approved'` so general 403s do not forcibly trigger portal unapproved redirect.
  - Safe avatar initials splitting: `String(name).trim().split(/\s+/).filter(Boolean)...` handles leading spaces and single names cleanly.
- **MEDIUM-07 (UI):** Checkout & Notifications
  - Included selected `shipping_option_key` in `checkoutEndpoints.place` payload.
  - Added name fallback in quotation line mappers.
  - Added `type="button"` on all non-submit auth buttons.
  - Added server-side `unread_only` filtering support to `NotificationController::index` and wired to `Notifications` screen.
- **LOW-01:** Cleaned Dead Duplicate Controller Action
  - Removed duplicate `InvoiceController::storePaymentNotice`, binding `payment-notices.store` route directly to `PaymentNoticeController::store`.

---

## Required Production Deployment Steps

When authorized to deploy to the live server, execute the following operational steps in order:

### 1. Pre-Deployment Database Backup
```bash
pg_dump -U postgres -d alfarid_production -F c -b -v -f /backups/alfarid_pre_task3_$(date +%Y%m%d_%H%M%S).dump
```

### 2. Git Pull Code
```bash
git pull origin fixes/task-audit-roadmap
```

### 3. Database Migrations
Run pending migrations:
```bash
php artisan migrate --force
```

### 4. Clear & Rebuild Application Caches
```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 5. Restart Queue Workers
Restart background workers to load updated job and service classes:
```bash
php artisan queue:restart
```

### 6. Build & Deploy Frontend Assets
```bash
pnpm --filter @alfarid/ui build
pnpm --filter storefront build
pnpm --filter backoffice build
```

### 7. Post-Deployment Verification Checklist
1. `GET /api/v1/wholesale/payment-notices` responds 200 with proper status badges.
2. `POST /api/v1/admin/payment-notices/{id}/reverse` accessible only by authorized staff.
3. Verify that `statement` report includes approved and reversed entries with dual balancing lines.
