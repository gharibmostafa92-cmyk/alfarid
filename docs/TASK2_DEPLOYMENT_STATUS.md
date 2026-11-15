# task2 — Deployment Status

Source roadmap: `task2.txt` (and `_archive_unused/tasks_execution/task2.txt`)
Live Cloud Run revisions and the current audit are in [Task execution audit](TASKS_EXECUTION_AUDIT.md).
Last deployed commit: `25ee590` (see `docs/.last-deployed`)
Date: 2026-11-15

# Deployed vs. Pending Deployment

### A. Already Deployed on Server
- Commit `25ee590` (`2026-11-10`, per `docs/.last-deployed`).
- Live server deployment state unverified from local workspace (no direct production SSH/DB access). None of the `task2.txt` (18-item Finance/Concurrency/Security/Precision/Frontend) fixes are deployed to production yet.

### B. Ready / Pending Server Deployment
All 18 items from `task2.txt` are implemented locally, verified via test suites, and ready for deployment:

- **Database Migrations & Schema Hardening**:
  - `CRITICAL-02`: Migration `2026_11_14_000003_rebuild_wallet_transactions_reference_alias_unique.php` drops `wallet_reference_unique` table constraint and recreates `wallet_transactions_reference_alias_unique` composite unique index scoped to `(customer_id, CASE ... END, reference_id, type)`.
  - `HIGH-04`: Migration `2026_11_12_000095_add_treasury_order_refund_type.php` guarded with `DB::getDriverName() !== 'pgsql'` and `DROP CONSTRAINT IF EXISTS` with lock timeout. `VerifyDatabaseSchema.php` updated with `treasuries_nonnegative_cash` constraint and comprehensive `EXPECTED_INDEXES` verification (`wallet_transactions_reference_alias_unique`, `treasury_transactions_reference_unique`, `treasury_transactions_opening_balance_unique`, `wallet_adjustments_one_reversal_idx`, `pos_shifts_one_open_user`, `pos_shifts_one_unsettled_drawer`).
  - `MEDIUM-05 (DB)`: Migration `2026_11_14_000004_index_finance_reconcile_runs_created_at.php` adding B-Tree index on `finance_reconcile_runs(created_at)`.
- **Concurrency & Lock Ordering**:
  - `HIGH-01`: `ReconcileInventory.php` CTE queries run under `StatementBudget::run(300000, ..., readOnly: true)`, eliminating `REPEATABLE READ` serialization failures (SQLSTATE 40001) on concurrent updates. Work queue cleanup and logging run under `READ COMMITTED`.
  - `HIGH-02`: `CreateTreasuryTransferTool.php::execute()` locks treasuries using `lockTreasuries([$fromId, $toId], $actor->id)` enforcing strictly ordered `id ASC` locks to eliminate deadlocks (SQLSTATE 40P01).
  - `MEDIUM-02`: `CreateTreasuryTransferTool.php` persists `transfer_id` in `$action->result['transfer_id']` rather than mutating `expected_versions`, maintaining pure optimistic concurrency mapping.
  - `MEDIUM-03`: `CreateExpenseTool.php::execute()` performs pre-flight status validation and receipt copying outside `DB::transaction()`, with automatic cleanup on failure/rollback.
  - `LOW-02` & `INFO-01`: `ExpenseService.php::create()` locks branch first (`lockActiveBranch`), then locks treasury via `lockTreasuries`, enforcing unified `Branch -> Treasury` lock order and rejecting inactive branches even when the funding treasury is central (`branch_id = null`).
- **Financial Precision & Reporting**:
  - `CRITICAL-03`: `ReportService.php::dashboard()` pushes time bounds directly to `salesEntries`, excludes return orders from `topProducts`, calculates net quantity sold `(qty - returned_qty)`, and wraps execution in `StatementBudget::run(10000, ..., readOnly: true)`.
  - `HIGH-05`: `CustomerWalletController.php::summary()` uses `BigDecimal` and formats bucket amounts as string decimals (`toScale(2)`). AI tools (`DailyCloseBriefingTool`, `ApproveTreasuryTransferTool`, `CreateExpenseTool`, `CreateTreasuryTransferTool`) use exact `BigDecimal` / `bccomp` comparisons and string representations.
  - `MEDIUM-04`: `ReportService.php::calculateProfitReport()` attributes missing costs to `orders.allocated_branch_id` matching `saleCogs`.
  - `MEDIUM-05 (Code)`: `ReconcileFinance.php` indexed cleanup on `finance_reconcile_runs(created_at)` and optimized wallet reconciliation.
  - `MEDIUM-07`: `Store\Customer\WalletController.php` replaces `whereDate` with half-open UTC bounds in business timezone (`Africa/Cairo`) and validates pagination (`page >= 1`).
  - `LOW-01`: `StatementBudget.php` safely restores statement timeout on success without leaking or raising 25P02 on aborted SQL.
- **Security Boundaries & Authorization**:
  - `CRITICAL-01`: `ReportController.php` & `DashboardController.php` implement `HasMiddleware` enforcing `can:finance.reports.view`. `AppServiceProvider.php` provides unified gate alias for `finance.reports.view` and `reports.view`.
  - `HIGH-03`: `CustomerWalletController.php` uses staff guard (`user('staff')`), supports amount up to `9999999999.99`, validates and passes reversal `reason`, and requires `Idempotency-Key` header.
- **Frontend Client & UI**:
  - `MEDIUM-01`: `packages/api-client/src/endpoints/operations/finance.ts`: Added `cancel` endpoint to `treasuryTransfersApi`.
  - `MEDIUM-06`: `packages/ui/src/financial-dashboard/FinancialDashboard.jsx`: Fixed async state toggle bug in `walletClick`, added null guard in `sendMessage`, formatted money with active currency symbol. Sanitized dummy credit cards in `initial-state.js`.
- **Pending Migrations to Apply on Production**:
  - `2026_11_14_000003_rebuild_wallet_transactions_reference_alias_unique.php`
  - `2026_11_14_000004_index_finance_reconcile_runs_created_at.php`
  - `2026_11_15_000001_include_credit_notes_in_wholesale_order_overallocated.php` (updates `wholesale_order_overallocated(target)` trigger function to include refunded `credit_note` `return_requests`)
  - `2026_11_15_000002_index_wholesale_reference_identity.php` (`$withinTransaction = false`; creates `CONCURRENTLY` functional index `wholesale_notices_reference_identity_idx` on `wholesale_payment_notices (wholesale_reference_identity(reference_number))`)
- **Wholesale, Pricing, Catalog & Portal Roadmap (`_archive_unused/tasks_execution/task2.txt`, 24 items — All Ready / Pending Deployment)**:
  - **Pricing & Catalog Security**: `PriceListItemController` defense-in-depth `price_list_id` scope checks (`CRITICAL-01`); `PriceListService::delete()` pre-captures affected `$productIds` before cascade delete for `RefreshCatalogRetailPrices` (`HIGH-04`); `PricingService::catalogContext()` bounded cache key including group/updated timestamps & `withStorePrice()` group eager-load (`MEDIUM-01`); `CustomerGroupController::index/show` central branch access assertion (`HIGH-08`); `Wholesale/ProductController` parent-category query grouping verified (`CRITICAL-04`).
  - **Wholesale Quotations, Quick Order & Exports**: `ProductVariant::scopeWholesalePurchasable` enforced across `QuotationService`, `WholesaleQuickOrderService`, `WholesalePriceListService`, and `CustomerWholesaleAssistant` (`HIGH-01`); `QuotationService` transactional row-locks & status re-checks (`HIGH-02`) and `priced_by` fallback when no system actor exists (`HIGH-06`); `WholesaleQuickOrderImport` row limit + `QuickOrderController::import` 500-row & `RejectExecutableSignature` guards + `WholesalePriceListService` 10k row cap (`HIGH-07`, `MEDIUM-09`); `QuoteAdvisor` & `WholesaleInsights` include `ConvertedToOrder` in acceptance rates (`MEDIUM-04`).
  - **Wholesale Payment Notices, Credit Notes & Ledger**: `WholesalePaymentNoticeService::releaseExcessAllocationsForCreditNote()` & `settleCreditNote()` FIFO re-settlement (`CRITICAL-02`); `settleFreeNoticeFifo()` branch-unrestricted settlement (`CRITICAL-03`); `create()` & `receiptCollisions()` exclude both `Rejected` and `Reversed`, and `approve()` maps `UniqueConstraintViolationException` to HTTP 409 (`HIGH-05`); `scopeWhereReferenceNumber` uses `wholesale_reference_identity(...)` (`MEDIUM-02`); `WholesaleInsights` & `WholesaleDailyBriefing` subtract covered allocations/credit notes and filter anomalies (`MEDIUM-05`); `statement()` uses `BigDecimal` (`MEDIUM-06`); `ScoreWholesaleReceipt` retries/timeout/atomic status update (`MEDIUM-09`); `BankStatementMatcher` two-pass `O(N)` amount-grouped matching (`LOW-01`).
  - **WhatsApp Assistant, Registration & Console Jobs**: `CustomerWholesaleAssistant` verifies channel/approval before calling Gemini on image/audio, forwards `$sender`, truncates handoff excerpts to 500 chars, and locks `CustomerChannel` (`HIGH-03`); `WholesaleRegistrationService::register()` pre-checks duplicate email/phone before R2 uploads (`MEDIUM-03`); `WholesalePaymentReminders` deterministic ordering, `whereNotExists` idempotency, and weekly cap ignoring `skipped`/`error`, plus `EscalateStaleWholesale` notifying `payment_notices.approve` (`MEDIUM-08`); `SpokenQuantity::stripQuantityTokens()` preserves digits inside SKU tokens (`LOW-02`); `WholesaleMonthlyStatements`, `MarkWholesaleOverdue`, `QuoteExpiryReminders`, and `ExpireQuotations` converted to `chunkById(200)` (`INFO-01`).
  - **Wholesale Portal Frontend (`MEDIUM-07`)**: Fixed `useApi` hooks/stale-closure pattern (`useApi.js`); `QuoteForm` draft deletion & state reset and `ReturnForm` functional `addFiles` & deterministic item ordering (`orders-screens.jsx`); `WholesalePortal` strict numeric route `id` parsing and batched `quickAddToCart` (`WholesalePortal.jsx`, `buy-screens.jsx`); `ApplicationStatus` self-service document re-upload wired to `/api/v1/wholesale/auth/documents` and `ResetPassword` 12-char minimum aligned with backend (`auth-screens.jsx`, `wholesale.ts`).
- **Required Deployment Commands**:
  1. `php artisan migrate --force`
  2. `php artisan db:verify-schema`
  3. `php artisan config:cache && php artisan route:cache`
  4. Frontend: `pnpm -r build`

---

## Historical Audit Notes & Context (Pre-2026-11-15)

## 2026-11-14 — task2 audit roadmap re-verification (root `task2.txt`)

Branch `fixes/task-audit-roadmap`, working tree changes. **Not deployed.**

- Re-verified all 17 items in root `task2.txt` (CRITICAL-01..02, HIGH-01..06, MEDIUM-01..07, LOW-01..02) against the current code and migrations.
- Gaps closed during verification:
  - `DailyCloseBriefingTool` transfer branch filter replaced correlated `whereHas`/`orWhereHas` with grouped `whereIn`/`orWhereIn` over branch treasury ids (MEDIUM-03, second location).
  - Test fixtures aligned with current guards: treasury balances set via `forceFill`, expense date uses `app.business_timezone`, wallet adjustments pass an idempotency key, cross-branch treasury-transfer `show` expects 403 (two-sided least privilege), wallet backfill test disables deferred triggers, treasury ledger test seeds an opening entry.
- Verification run on 2026-11-14:
  - `php artisan migrate:fresh --env=testing --force` (after flaky first attempts).
  - `tests/Feature/Finance`: 210 passed, 1,453 assertions.
  - `tests/Feature/Maintenance`: 18 passed, 41 assertions.
- Deployment remains pending: commit, push, run migrations on staging, then deploy.

## 2026-11-12 — task2 audit roadmap closeout (F1–F18)
Branch `fixes/task-audit-roadmap`, uncommitted working tree. **Not deployed.**
- New migrations: `2026_11_12_000068`–`000073` (treasury uniqueness + sign CHECK, treasury branch RESTRICT, precision alignment, `orders.returned_at`, `treasury_transfers.rejected_by`, stock-movement report index).
- Verification: Finance suite 127/127 (1,122 assertions); targeted Pos shift tests 3/3; new sign-guard and `returned_at` period tests pass; `php -l` clean on edited/new PHP files.
- Frontend: `pnpm -r typecheck` clean (7/7); `pnpm -r build` PASS (storefront, backoffice, POS).
