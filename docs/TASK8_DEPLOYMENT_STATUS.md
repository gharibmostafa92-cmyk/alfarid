# Task 8 — Treasury, Wallet, Reports & Financial Operations Deployment Status

Source roadmap: `_archive_unused/tasks_execution/task8.txt` (15 findings: `F01`–`F15`).
Date: 2026-11-12
Branch: `fixes/task-audit-roadmap` (local working tree; **not deployed to live production**)

# Deployed vs. Pending Deployment

### A. Already Deployed on Server

- **Live server status for Task 8 changes:** None of the Task 8 (`F01`–`F15`) fixes or migrations are deployed to production Cloud Run / Neon. Live server runtime verification was not executed in this task; treat live state as unverified against prior baseline commit `25ee590` / Cloud Run revision `alfarid-api-rel20261110b`.

### B. Ready / Pending Server Deployment

All items below are implemented locally in the repository and require release approval, migration execution by the migration-owner role, backend container deploy, and frontend package/backoffice rebuild.

#### 1. Database Migrations (Must Run with Migration-Owner Role Before App Traffic)

- **`2026_11_12_000090_task8_treasury_and_inventory_schema_hardening.php`** (`withinTransaction = false`, PostgreSQL-guarded):
  - Creates unique partial index `treasury_transactions_opening_balance_unique` on `treasury_transactions (treasury_id)` where `type = 'opening_balance' AND reference_type IS NULL AND reference_id IS NULL`.
  - Validates existing check constraint `treasury_transactions_type_sign_check` on `treasury_transactions`.
  - Ensures `treasury_transfers_approver_required` (`status <> 'approved' OR (approved_by IS NOT NULL AND approved_by <> created_by)`) is present and validated, and drops legacy `treasury_transfers_separate_approver` if still present.
  - Creates concurrent partial index `stock_movements_inventory_last_activity_idx` on `stock_movements (product_variant_id, branch_id, created_at DESC) WHERE type NOT IN ('reserve', 'release_reserve')` for `inventoryReport` `LEFT JOIN LATERAL` lookups.
  - Backfills any historical `orders.status = 'returned'` rows with `returned_at IS NULL` using `COALESCE(updated_at, created_at)`.
- **Driver guards on existing migrations (`F15`):** Added `DB::getDriverName() === 'pgsql'` guards in `2026_10_24_000009`, `2026_10_28_000008`, `2026_11_10_000002`, `2026_11_12_000022`, and `2026_11_12_000023` (forward no-ops on live databases where already applied).
- **Schema gate update:** `app/Console/Commands/Database/VerifyDatabaseSchema.php` now asserts `treasury_transactions_opening_balance_unique` and `treasury_transfers_approver_required`.
- **Pre-migration rehearsal checks:**
  1. Confirm zero duplicate null-reference `opening_balance` rows in `treasury_transactions`.
  2. Confirm zero invalid `treasury_transactions` sign rows and zero `approved` transfers missing `approved_by` or self-approved.
  3. Run `php artisan migrate --force` under migration-owner credentials, then run `php artisan db:verify-schema`.

#### 2. Backend Code Changes (API Container Rebuild & Deploy)

- **Treasury, Expense & Guard Hardening (`F04`, `F06`, `F07`, `F09`, `F11`, `F12`, `F13`, `F14`):**
  - `TreasuryService`: Constructor-injected `BranchAccess`; active-branch & non-null branch validation for `pos_drawer` even on `0.00` opening balance; unified `Expense` & `WholesalePaymentNotice` dependency checks + `SQLSTATE 23503` catch in `deleteAccount()`; `lockTreasuries()` in `createTransfer()`; strict missing-treasury rejection in `approveTransfer()`; `lockForUpdate` inside `assertDrawerAvailableForTransfer()`.
  - `FinanceWriteGuards`, `DeleteTreasuryTool`, `ApproveTreasuryTransferTool`, `CreateExpenseTool`: `BigDecimal` math (no float comparisons in `suggestTopUp`, `ledgerVersion`, or `isZero`), central-access & `pos_drawer` branch rules in `creationBlocker`, unified `deletionBlockers`.
  - `ExpenseService`: Path traversal & allowed prefix validation (`expenses/` or `expense-receipts/`) on `attachment_path`.
  - Controllers (`TreasuryController`, `TreasuryTransferController`, `ExpenseController`, `PosShiftAdminController`, `ReportController`): Standardized `$request->user('staff')`, minimal eager relation projections (`treasury:id,name,code`, `fromTreasury`/`toTreasury` without `current_balance`, staff without email/phone), `403` on out-of-branch transfer filter probes, safe attachment download path validation, unified `max:999999999999.99`.
- **Wallet & Idempotency (`F08`, `F12`):**
  - `CustomerWalletTransaction`: Explicit `$fillable`.
  - `WalletService`: Explicit `ALLOWED_TYPES` whitelist, `TEST_TYPES` gated to `app()->runningUnitTests()`, consistent `0.00` sign validation.
  - `WalletAdjustmentService`: Blocks `Suspended`/`Rejected` customers in `adjust()`, locks `WalletAdjustment` inside `DB::transaction` before authorization and `canReverse()` checks in `reverse()`.
  - `PosShiftAdminController` (`reopen`, `reject`, `forceClose`) & `TreasuryTransferController` (`reject`): Optional `Idempotency-Key` support via `OperationIdempotencyService`.
- **Force-Close Precision (`F02`):**
  - `PosShiftAdminController::forceClose` & `PosService::forceCloseShift`: Enforces `decimal:0,2` with `max:999999999999.99`, passes normalized decimal strings into `BigDecimal` instead of `(float)`.
- **Reports & Reconciliation (`F03`, `F05`, `F10`, `F11`):**
  - `StatementBudget::run(..., bool $readOnly = false)`: Supports `REPEATABLE READ READ ONLY` for read-only reports/snapshots while preserving writer compatibility.
  - `ReconcileFinance`: Wraps only the read snapshot query in `StatementBudget::run(300000, ..., readOnly: true)`; executes `FinanceReconcileRun` creation and 14-day cleanup in `READ COMMITTED`.
  - `ReportService` & `ReportController`: Split `calculateProfitReport` into `INNER JOIN` on bounded `stock_movements` `$saleDates` + bounded `returnEntries()`, pro-rated RMA tax/shipping vs merchandise, dropped `updated_at` fallback on `$manualReturns`, failed closed (`503`) on `LockTimeoutException` in `inventoryReport()`, ensured lock TTL strictly exceeds statement timeout, normalized `dead_days` to canonical discrete buckets, lowered HTTP inventory statement budget to `15000ms`, and replaced `float` casts in `dashboard()`/`shiftsReport()` with `moneyFloat()` / `BigDecimal`.

#### 3. Frontend Package & Application Changes (Rebuild Required)

- **`bags-platform/frontend/packages/api-client/src/endpoints/operations/finance.ts` (`F02`, `F08`):**
  - Removed `remove` from `expensesApi` so cancellation goes strictly through `void`.
  - Updated `shiftsApi.forceClose` signature to `(id, { actual_closing_cash, reason }, idempotencyKey?)` matching backend `decimal:0,2` contract.
  - Wired automatic/explicit `Idempotency-Key` generation via `newIdempotencyKey()` across `expensesApi.void`, `treasuryTransfersApi.approve`/`reject`, `shiftsApi.approve`/`reject`/`reopen`/`forceClose`.

#### 4. Environment, Queue, Scheduler & Operational Checklist

- **Environment variables:** No new `.env` secrets or new variables required.
- **Queue / Scheduler:** `finance:reconcile` signature and scheduler cadence unchanged; restart queue workers / deploy updated API container normally after migrations complete.
- **Operational commands (in order, during maintenance/release window):**
  1. Run pre-migration checks and `php artisan migrate --force` (migration-owner role).
  2. Run `php artisan db:verify-schema`.
  3. Deploy updated backend API image and restart worker/scheduler containers.
  4. Rebuild `@alfarid/api-client` and frontend apps (`pnpm -r build`) and deploy updated static/SSR bundles.
