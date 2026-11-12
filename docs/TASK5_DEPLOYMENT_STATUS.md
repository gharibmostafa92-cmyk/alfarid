# task5 — Deployment Status

Source roadmap: `_archive_unused/tasks_execution/task5.txt` (Finance, Treasury, POS Shift Settlement, Wallet Adjustments, Reporting, Reconciliation, and Migration Safety — F01–F21).
Live Cloud Run revision baseline and historical audit notes are tracked in [Task execution audit](TASKS_EXECUTION_AUDIT.md) and [`docs/.last-deployed`](.last-deployed).
Date: 2026-11-12

---

# Deployed vs. Pending Deployment

### A. Already Deployed on Server

- **Live revision baseline (last verified in audit log, 2026-11-10):**
  - Git deployment marker (`docs/.last-deployed`): `25ee590`.
  - Cloud Run Europe (`europe-west3`, `alfarid-api`): revision `alfarid-api-rel20261110b` (`api@sha256:617d6f76075126f11308858f45598677e6dc93b8a96ec1104f681282be74ea1b`); `us-central1` disabled.
  - Database role note: runtime role `alfarid_pilot_runtime` cannot execute DDL in `public`; schema changes must be applied with the migration-owner role.
- **Task5 confirmation status:**
  - Live production Cloud Run / Neon access was **not** used in this session; none of the `task5.txt` fixes or migrations below are assumed or confirmed to be running on production. Live production state for `task5.txt` items is **unverified / not deployed**.

### B. Ready / Pending Server Deployment

All items below are implemented and verified locally in the working tree on top of the unpushed roadmap work and require staging rehearsal + production deployment:

#### 1. Database Migrations & Seeders Pending Production Application

1. **New migration — POS shift settlement timestamp (`F03`):**
   - File: `bags-platform/backend/database/migrations/2026_11_12_000082_add_settled_at_to_pos_shifts.php`
   - Action: Adds nullable `pos_shifts.settled_at` timestamp under `SET LOCAL lock_timeout = '3s'`, backfills existing `approved` shifts with `settled_at = COALESCE(approved_at, updated_at, now())`, and guards rollback cleanly.
   - Deployment step: Rehearse on a Neon clone/branch with migration-owner credentials, then run `php artisan migrate --force`.
2. **Hardened existing/pending unapplied migrations (`F07`):**
   - `bags-platform/backend/database/migrations/2026_11_12_000053_guard_wallet_adjustment_amount.php`: Now runs `$withinTransaction = false`, adds `wallet_adjustments_amount_positive` with `NOT VALID` inside a `SET LOCAL lock_timeout = '3s'` transaction, then validates in a separate `VALIDATE CONSTRAINT` statement (`SHARE UPDATE EXCLUSIVE`, avoiding long `ACCESS EXCLUSIVE` locks).
   - `bags-platform/backend/database/migrations/2026_11_12_000070_align_treasury_shift_expense_precision.php`: `down()` now pre-scans `pos_shifts` and `expenses` for values with `ABS(col) >= 10000000000` and aborts with `RuntimeException` before attempting a narrowing `numeric(12,2)` table rewrite.
3. **Already-present prerequisite Task5 schema migrations (also pending prod if unapplied):**
   - `2026_11_12_000008_treasury_ledger_immutability_trigger.php`, `000032`, `000068_treasury_ledger_integrity.php` (`F02`: append-only + `TRUNCATE` trigger + `RESTRICT` FK on `treasury_transactions`).
   - `2026_11_12_000073_drop_legacy_treasury_transactions_type_check.php` (`F01`: drops conflicting legacy `treasury_transactions_type_check` so `customer_receipt_reversal` succeeds).
4. **Seeder update — POS shift permissions (`F18`):**
   - File: `bags-platform/backend/database/seeders/FinancialPermissionSeeder.php`
   - Action: Adds `pos_shifts.view`, `pos_shifts.approve`, `pos_shifts.force_close`.
   - Deployment command: `php artisan db:seed --class=FinancialPermissionSeeder --force` before releasing backend traffic so `PosShiftAdminController` routes never raise `PermissionDoesNotExist`.

#### 2. Backend Code Changes Pending Deployment

- **Slice 1 — Permissions, Atomicity, Precision & Localization (`F01`, `F02`, `F04`, `F18`, `F19`):**
  - `WalletAdjustmentService::recordAdjustment`: Wrapped inside explicit `DB::transaction()`, converts `MathException` to `BusinessException`, enforces `9999999999.99` decimal balance/adjustment cap even when `$idempotencyKey === null`.
  - UTF-8 Arabic CLI & JSON messages verified across `ReconcileFinance.php`, `PosShiftAdminController.php`, and `TreasuryTransferController.php`.
- **Slice 2 — Treasury & Expense Hardening (`F08`, `F09`, `F10`, `F12`, `F20`, `F21`):**
  - `Treasury.php` & `TreasuryService.php`: Removed `current_balance` from `$fillable`; filtered `createAccount`/`updateAccount` inputs with `Arr::only` and `forceFill(['current_balance' => '0.00'])`; blocked branch changes, deactivations (`pending`), and deletes across `treasury_transfers`; replaced `bccomp` with `BigDecimal` parser `parseDecimal()` and `999999999999.99` ceiling; verified `$mainSafe` `is_active` under `lockForUpdate()`; refactored `assertDrawerAvailableForTransfer(Treasury $treasury)` to avoid N+1 re-queries; normalized/clamped rejection reasons to 500 chars.
  - `ExpenseService.php`: Verified `$lockedTreasury->is_active` under `lockForUpdate()` before burning `generateNextNumber('expense')`; normalized/rejected empty `void()` reasons and clamped to 500 chars.
  - `FinanceWriteGuards.php` & `DeleteTreasuryTool.php`: Added `treasury_transfers` and `PosShift` blockers across both `from_treasury_id` and `to_treasury_id`.
- **Slice 3 — Shift Settlement, Scoping & Idempotency (`F03`, `F13`, `F14`, `F20`):**
  - `PosShift.php` & `TreasuryService::settleShiftToMainSafe`: Rejects negative `actual_closing_cash`; tracks `settled_at` inside transaction to eliminate infinite zero-cash replay.
  - `ExpenseService::void` & `ExpenseController::destroy`: Moved `assertExpenseAccess` inside `DB::transaction` on locked `$expense`, added optional `OperationIdempotencyService` support via `Idempotency-Key` header.
  - `PosShiftAdminController.php` & `TreasuryTransferController.php`: Restricted `PosShiftAdminController::index` to reject out-of-branch filters with `403`; updated `TreasuryTransferController::index`/`show` to `OR` branch visibility (with eager `loadMissing`) while retaining `AND` for `approve`/`reject`; wired `OperationIdempotencyService` to `approve()` endpoints.
- **Slice 4 — Reports, Reconciliation & Statement Timeout (`F05`, `F06`, `F11`, `F15`, `F16`, `F17`):**
  - `StatementBudget.php`: Added `try ... finally` restoration of `statement_timeout` guarding aborted-transaction rollback safety.
  - `ReportService.php`: Moved `periodBounds` validation before `StatementBudget::run`; fixed cross-period order revenue duplication via `HAVING MIN(created_at) >= ? AND < ?` and bounded return legs (`boundSalesToPeriod: false`); unified `inventoryReport` cache key under per-branch `Cache::lock` with pure `stripInventoryCosts()` projection and business-timezone dead-stock cutoff; eliminated PHP float arithmetic drift via `BigDecimal` accumulation and `moneyFloat()`; enforced strict `!Y-m-d` validation and bound `$businessToday` in `calculateSuppliersReport`.
  - `ReconcileFinance.php`: Scoped wallet reconciliation (`w_ledger`, `w_sums`, `w_problems`, `customers` count) to run only when `--branch_id` is `NULL`, isolating branch-level runs cleanly.

#### 3. Frontend Package Changes Pending Build & Deploy

- `bags-platform/frontend/packages/api-client/src/endpoints/operations/finance.ts`: Added optional `idempotencyKey?: string` on `expensesApi.void` and `shiftsApi.approve`, plus `shiftsApi.reject`, `shiftsApi.reopen`, and `shiftsApi.forceClose` endpoints matching `PosShiftAdminController`.
- **No new environment variables or queue worker topology changes required** beyond redeploying the updated API image, rebuilding frontend apps (`backoffice` / workspace packages), running `php artisan migrate --force` (migration owner), and running `php artisan db:seed --class=FinancialPermissionSeeder --force`.
- **Operational deployment sequence:**
  1. Rehearse migrations (`000008`, `000032`, `000053`, `000068`–`000073`, `000082`) on a Neon Staging Branch with the migration-owner role.
  2. Apply `php artisan migrate --force` and `php artisan db:seed --class=FinancialPermissionSeeder --force` during deployment window.
  3. Build & push updated Cloud Run API container image (`alfarid-api`) and promote after `/up` and `/health/ready` checks pass.
  4. Rebuild & deploy frontend apps consuming `@alfarid/api-client`.

---

## Verification Record (Local Working Tree)

- `Task5Slice1VerificationTest`: **5 passed / 17 assertions**
- `Task5Slice2TreasuryHardeningTest`: **6 passed / 13 assertions** (+ 36/36 in `ExpenseVoidTest`, `FinanceExpenseToolsTest`, `FinanceWriteToolsTest`, `WalletCheckoutTest`)
- `Task5Slice3ShiftAndTransferTest`: **6 passed / 34 assertions** (+ 47/47 in `ExpenseVoidTest`, `PosEndpointsTest`, `FinanceTransferToolsTest`, `FinanceReadToolsTest`)
- `Task5Slice4ReportsReconcileAndMigrationsTest`: **7 passed / 33 assertions** (+ 61/61 in `AdminReportsTest`, `InventoryReportCostGatingTest`, `FinancialAuditRoundTwoTest`, `FinanceIntegrityTest`, `OperationsAuditCompletionTest`)
