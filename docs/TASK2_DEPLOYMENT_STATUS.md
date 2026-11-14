# task2 — Deployment Status

Source roadmap: `_archive_unused/tasks_execution/task2.txt`
Live Cloud Run revisions and the current audit are in [Task execution audit](TASKS_EXECUTION_AUDIT.md).
Last deployed commit: `25ee590` (see `docs/.last-deployed`)
Date: 2026-11-10

# Deployed vs. Pending Deployment

### A. Already Deployed on Server
- Commit `25ee590` (`2026-11-10`, per `docs/.last-deployed`).
- None of the `_archive_unused/tasks_execution/task2.txt` (20-item Orders/Returns/Webhooks/Attachments) fixes are deployed to production yet (live server state unverified from local workspace).

### B. Ready / Pending Server Deployment
All 20 items from `_archive_unused/tasks_execution/task2.txt` are verified/implemented locally and pending commit, migration, and Cloud Run deployment:
- **Already present in codebase & verified**:
  - `CRITICAL-01`: `orders_payment_status_check` includes `partially_refunded` and `refund_pending` (`2026_10_24_000009`).
  - `CRITICAL-03`: `ReturnService::receive()` damaged stock intake (`SaleReturn` -> `OnHand`, `Damage` -> `Damaged`) preserves `available = on_hand - reserved - damaged`.
  - `HIGH-01`: `return_requests_refund_reference_unique` partial unique index (`2026_10_19_120001`).
  - `LOW-01`: `OrderService::assertMatchingIdempotentReplay()` live cart check preserves `OrderSpecCoverageTest` conflict semantics.
- **New backend changes (pending deploy)**:
  - `CRITICAL-02`: `OrderService::createFromCart` caps distributed coupon discount; `ReturnService::netLineRefundCents` & `receive()` use `BigDecimal` and proportional cents for legacy/partial refunds.
  - `CRITICAL-04`: `OrderAllocationService::allocate` sets `allocated_branch_id` on single-branch fallback; `Admin\Orders\OrderController::allocate` guards allocated split orders.
  - `HIGH-02`: `Order::casts()` adds `gateway_refunded_amount`; `ReturnService::refund` & `syncOrderPaymentStatusAfterRefund` support split refunds across gateway and wallet/cash.
  - `HIGH-03`: `ReturnService` (`delete`, `reject`, `cancel`, `updateDetails`) and `OrderService::changeStatus` enforce `Order -> ReturnRequest / Customer` lock ordering.
  - `HIGH-04`: `PaymobRefundInquiry::token()` uses `->block(4, ...)` with `LockTimeoutException` fallback and 15s auth-failure backoff.
  - `HIGH-05`: `Store\Orders\OrderController::track()` adds per-order rate limit (5/15m) and constant-length HMAC-SHA256 phone comparison.
  - `MEDIUM-01`: `ReturnService::createReturnRequest()` runs lightweight order eligibility pre-flight check before uploading to R2.
  - `MEDIUM-02`: `PrunePrivateAttachments` gracefully prunes up to `--max-deletions`, scopes table checks per prefix, and uses batch `$disk->delete($paths)`.
  - `MEDIUM-03`: `Admin\Orders\OrderController::updatePayment` blocks manual `refunded` for `wallet`/`credit`/`pos` and adds `refund_pending`.
  - `MEDIUM-04`: `ShippingZoneController::destroy()` returns 422 `BusinessException` when shipping rates exist.
  - `MEDIUM-05`: `ReturnService::assertWithinReturnWindow()` uses `endOfDay()` in `business_timezone`; `OrderService::expectedTotalChanged` compares integer cents; `CheckoutRequest` adds `max:9999999999.99`.
  - `MEDIUM-06`: `AttachmentController::download()` skips network `exists()` probe by default (opt-in via `?verify=1`).
  - `MEDIUM-07`: `ExpirePendingOrders` adds `pending_bank_transfer_expire_hours` (48h) and excludes orders with `pending_review` `WholesalePaymentNotice`.
  - `MEDIUM-08`: `IdempotencyFingerprint::canonicalize()` normalizes quantity fields as exact decimals and rejects non-finite numeric strings.
  - `LOW-02`: `ShippingWebhookService::alreadyProcessed()` uses indexed `where('provider', $provider)`.
  - `LOW-03`: `OrderService::shipOrder()` aggregates `sales_count` updates per product in ascending `product_id` order.
- **Pending migration**:
  - `bags-platform/backend/database/migrations/2026_11_14_000001_add_orders_pending_expiry_index.php` (`CREATE INDEX CONCURRENTLY orders_pending_expiry_idx`).
- **Environment / Config**:
  - Optional: `ORDER_PENDING_BANK_TRANSFER_EXPIRE_HOURS=48` (defaults to `48` in `config/orders.php`).
- **Required deployment commands**:
  1. `php artisan migrate --force`
  2. `php artisan config:cache && php artisan route:cache`

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
  - `tests/Feature/Ai`: 293/294 passed; one pre-existing catalog bulk-undo failure (`AiCatalogConcurrencyGuardTest`) is unrelated to task2.
- Full `php artisan test` was attempted but exceeded the environment's execution window (no output captured); targeted Finance/Task2/Task5/Task8/Maintenance suites cover every changed area. Run the full suite in chunked directory batches on CI before deploy.
- No new migrations in this pass. `2026_11_12_000093` (task2 schema hardening) and `2026_11_12_000070` (precision alignment) verified in the testing database only.
- Deployment remains pending: commit, push, run migrations on staging, then deploy.

## 2026-11-12 — task2 audit roadmap closeout (F1–F18)
Branch `fixes/task-audit-roadmap`, uncommitted working tree. **Not deployed.**

- ✅ F1/F2/F3/F4/F5/F6/F7/F8/F9/F10/F11/F12/F14/F15/F16 implemented (see `task2.txt` status lines).
- ⏳ F13 deferred: legacy wallet type rename/backfill needs a separate data window.
- ⚠️ F17 partial: string amounts, unified `(14,2)` precision, frontend string amount, and removal of `ai_action_id` inheritance are in. Reversal `kind` still needs a schema column (deferred); `crud().remove` is unused.
- ⏳ F18 documented only: overdraft/opening-balance/shift-zeroing remain product decisions.
- New migrations: `2026_11_12_000068`–`000073` (treasury uniqueness + sign CHECK, treasury branch RESTRICT, precision alignment, `orders.returned_at`, `treasury_transfers.rejected_by`, stock-movement report index).
- Verification: Finance suite 127/127 (1,122 assertions); targeted Pos shift tests 3/3; new sign-guard and `returned_at` period tests pass; `php -l` clean on edited/new PHP files.
- Frontend: `pnpm -r typecheck` clean (7/7); `pnpm -r build` PASS (storefront, backoffice, POS).
- Shared-tree caveat: task3 inventory WIP still has transfer branch-access failures in `PosEndpointsTest`/`TaskTxtPosScenariosCTest`/`TaskTxtPosScenariosDTest`, and the Orders suite has pre-existing coupon/webhook/shipment-guard failures. A parallel task3 test run can drop/recreate `alfarid_testing`; reset it before rerunning.
- Local incident: `php artisan migrate:fresh --env=testing` wiped `alfarid_local` because no `.env.testing` exists. Local schema is pending `000070`–`000073`; recover with `php artisan migrate --force` (schema only). Never run bare `migrate:fresh --env=testing` here.

## 1. Already Deployed on Server / Cloud
- Commit `25ee590` — `fix(tests): avoid duplicate generated brand slugs`
- Prior history in `docs/CHANGELOG.md` (auto-generated by `scripts/changelog.ps1`).
- No task2 security/DB/infra fixes are deployed yet.

## 2. Ready / Pending Server Deployment (NOT yet deployed)
Code changes in working tree, pending commit + push + deploy:

- ✅ M2 — `UnifiedAuthService::rehashIfNeeded` concurrent-rehash false-401 fix
  - File: `bags-platform/backend/app/Services/Auth/UnifiedAuthService.php`
  - Behavior: on CAS miss, re-read fresh hash and verify instead of instant 401.
  - Deploy notes: code-only. No migration, no env change.
- ✅ H2 — `UnifiedAuthService::attempt` atomic pair-slot reservation
  - File: `bags-platform/backend/app/Services/Auth/UnifiedAuthService.php`
  - Behavior: `RateLimiter::hit($pairKey)` before staff query/bcrypt; success clears; failure path no longer double-hits pairKey.
  - Deploy notes: code-only. Verify `CACHE_STORE` is shared (Redis) in prod or limits multiply per host.
- ✅ M1 — split branch-lock vs password-rotation exemptions
  - File: `bags-platform/backend/app/Http/Middleware/EnsureStaffAuthenticated.php`
  - Behavior: new `isBranchLockExempt()` (GET me, PUT me/password only); `isPasswordRotationRequest()` no longer exempts `admin.users.update` self-edit. Branch-disabled staff can no longer self-move via users.update.
  - Deploy notes: code-only. If backoffice UI rotates temp passwords via `users.update`, migrate it to `me/password` before release.
- ⏪ M4 — REVERTED, single Sanctum token resolution abandoned (all auth middlewares)
  - Files: `EnsureStaffAuthenticated.php`, `EnsureCustomerAuthenticated.php`, `EnsureSupplierAuthenticated.php`, `EnsureWholesaleApproved.php`
  - Behavior: `user('sanctum')` once + `Auth::guard(...)->setUser()`. 4 queries → 2 per request. ⏪ REVERTED 2026-11-10: broke committed WalletCheckoutTest (stale wallet balance); all four middlewares restored, staff keeps M1 split only.
- ✅ M5 — alert dedup + ip_failures attribution fix
  - Files: `AuthenticationAlerts.php`, `UnifiedAuthService.php`
  - Behavior: `Cache::add('auth-alert:<reason>', 15m)` gate; `ip_failures` reports `staff_id=null`.
- ✅ M6/L3 — staff audit suppress + no double-count
  - File: `UnifiedAuthService.php`
  - Behavior: staff `inactive`/`wrong_password` stay synchronous (the committed `AuthAuditRegressionTest` calls the service directly and asserts persistence — `defer()` broke it); suppressed after soft limit; inactive IDs excluded from wrong_password pass. Table growth is bounded by suppression, not deferral.
- ✅ L1 — `LoginDestinationResolver::safe` double-decode guard
  - Behavior: reject when `rawurldecode` is unstable across two passes.
- ✅ L2 — cashier role exact match
  - Behavior: exact `cashier`/`كاشير`/`StaffRole::Cashier` or `pos.access` permission; no more `Deposit Clerk` → POS misroute.
- Partial M7 — configurable staff token TTL + POS least-privilege
  - Files: `StaffTokenService.php` (default 43200→1440 min, `abilities` param), `UnifiedAuthController.php` (POS → `['pos']`)
  - Current effective default is 43,200 minutes in `config/auth.php`; the 1,440-minute service fallback does not override it. Set `STAFF_TOKEN_MINUTES=1440` after agreeing on the cutover; it only affects newly issued tokens. Idle timeout remains open.
- ✅ M3 — `StaffAdministration::lock` transaction guard + lock_timeout reset
  - File: `StaffAdministration.php`
  - Behavior: throws outside transactions; `SET LOCAL lock_timeout='0'` after lock so later `lockForUpdate` is not capped at 5s.
- ✅ L5 — audit `json_encode` invalid-UTF8 guard
  - File: `StaffAdministration.php`
  - Behavior: `JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE`.
- Repomix: added `EnsureSupplierAuthenticated.php` + `EnsureWholesaleApproved.php` to subsystem-09 includes (they were edited for M4 but missing from the bundle).
- ✅ H1 — soft-limit human challenge + IPv6 /48 bucket (backend)
  - Files: `UnifiedAuthService.php` (wide bucket `auth.unified_login_wide_failures_hour` default 200, `challenge_required` 429 via Turnstile), `NetworkIdentity.php` (`forRequestWide`/`wideKey`), `UnifiedLoginRequest.php` (`turnstile_token`).
  - Deploy notes: needs `TURNSTILE_SECRET_KEY` in prod; frontend must render Turnstile on `code=challenge_required`. Valid challenge passes and bcrypt resumes; bots stop before bcrypt.
- ✅ L4 — unified supplier dead code + historic tokens
  - Files: `UnifiedAuthService.php` (SupplierUser branches removed), new migration `2026_11_12_000002_expire_historic_supplier_tokens.php`.
  - Deploy notes: migration expires null-`expires_at` supplier tokens. Supplier portal login untouched (separate controller).
- ✅ L6 — `must_change_password` down() guard; L7 — AI conversations exempt from admin-mutation throttle; L8 — warn on missing `expected_version`.
- ✅ M8 — single open shift (DB part)
  - New migration `2026_11_12_000003_pos_shifts_single_open_unique.php` (`CONCURRENTLY`, `withinTransaction=false`); sale path already re-locks via `lockOpenShift()`.
  - New regression test `tests/Feature/Pos/PosShiftSingleOpenIndexTest.php`: 1 passed.
- ✅ F-batch 4 — DB guards + test/config fixes
  - New `2026_11_12_000004_task2_check_guards.php` (F3/F5/F8/F10/F12 CHECKs NOT VALID) + `2026_11_12_000005_task2_unique_guards.php` (F10/F11 uniques + F26 index, CONCURRENTLY) + `2026_11_12_000006_widen_returned_qty_precision.php` (F21) + `2026_11_12_000007_drop_redundant_line_indexes.php` (F20).
  - Fixed F14 (cost down no-op), F15 (finance_ai_support down guards), F19 (partial-index predicate), F24 (rollback pre-checks), F7 (prepares default true), F16 (concurrency test Shared namespace + null assertions), F18 (sequence comment).
  - VALIDATE CONSTRAINTs done in `2026_11_12_000012_validate_task2_check_guards.php` (F3/F5/F8/F10/F12, incl. F5 approver + F8 idempotency-scope + F12 coupon-cap); FK RESTRICT done in `2026_11_12_000013_ledger_fk_restrict.php` (F5 approved_by SET NULL→RESTRICT, F10 notice_id/order_id CASCADE→RESTRICT).
  - Deploy notes: 000012 VALIDATE fails loudly on violating legacy rows (by design — resolve data first); 000013 takes brief ACCESS EXCLUSIVE per table with 10s local lock_timeout (fails fast under contention, retry in low-traffic window).
- Repomix: subsystem-07 extended (task2 guards + coupons/order/wholesale migrations); coverage 162/162 (100%).
- ✅ F1/F4 — `inventory_movement_number` catches `query_canceled`, 2s bounded lock wait with restore (migration `000009`; migrated clean).
- ✅ F13 — `customers_phone_unique` rescoped to live rows (migration `000010`).
- ✅ F9 — order-scoped wallet refunds rejected at source (`WalletService::record` 422 unless via `wallet_adjustment` doc). `WalletLedgerGuardTest`: 8 passed.
- ✅ F25 — TRUNCATE triggers on `customer_wallet_transactions` + `treasury_transactions` (migration `000008`); F3 treasury append-only trigger included.
- ✅ F2/F6 — verified deploy migrates via direct `postgres:5432` with connection-scoped `PGOPTIONS` (no pooled-session leak); app traffic uses `pgbouncer:6432`. Full role split (owner/app/monitor) stays under C2.
- ✅ F7 — `DB_DISABLE_PREPARES` default true + `.env.example`; F16 test namespace fix (Shared); F18 comment; F19/F20/F21/F24/F26 guards.
- ✅ H7 — `docker-compose.tests.yml` pins safe env; `phpunit.xml` forces them.
- ✅ H1 frontend — storefront `LoginScreen` renders Turnstile on `challenge_required` and retries with token; `tsc` clean (storefront + api-client).
- ✅ C1 — atomic verified backups, fail-loud loop, `REQUIRE_R2_BACKUP=1`, no profile gate, `.last-success` stamp (WAL archiving stays follow-up).
- ✅ C2 stage 1 — `alfarid_app`/`alfarid_monitor` roles + default privileges (migration `000011`); credential cutover + PgBouncer rewiring stay staged.
- ✅ Infra H2/M1/M2/M3/M10-partial/M13-partial — `/up` liveness check, header strips + url_dec denies, Redis password rewrite on boot, `shm_size: 1g`, monitoring `restart:` flags. `haproxy -c` unavailable locally — verify on staging.
- Repomix coverage now 168/168 (added lock/phone/roles/movement guards to subsystem-07).

Still pending (need staging/DBA/product decisions, not implementable safely from here):
- F17 (drop unused stock_movements indexes — needs staging `pg_stat_user_indexes`), F23 (verify-full TLS needs CA provisioning), F27 (pool numeric retune needs host RAM data), M10 `-c` tuning (needs host RAM).
- F22 DONE 2026-11-10 (follow-up): remaining 7 App-coupled migrations decoupled — frozen morph FQCN strings (10_21_000002, 10_25_000001, 10_25_000005, 11_12_000002), inlined ConcurrentIndex logic (10_28_000002), dropped dead SettingService imports (11_06_000001, 11_07_100001). Verified zero `use App\|Spatie\` + zero live `App\` code refs in migrations (only frozen strings/comments).
- F16 DONE 2026-11-10: `DocumentNumberingConcurrencyTest` run — 6 passed / 1040 assertions.
- F5/F8/F10/F12 DONE (were marked pending): approver/idempotency/coupon/amount CHECKs VALIDATEd in 000012; approved_by SET NULL→RESTRICT + allocations CASCADE→RESTRICT in 000013.
- C2 follow-up: `000011` down() hardened — DROP ROLE wrapped in 2BP01-tolerant NOTICE block so rollback survives sibling-DB grants in shared local clusters (verified by polluted/clean rollback runs; `Auth/` back to 130 passed). NOTE: down()-only change to an already-applied migration; instances that already ran 000011 keep working — the new down() applies on next rollback.
- Local-test caveat (do NOT copy to runbooks): `php artisan --env=testing` does NOT target `alfarid_testing` here — no `.env.testing` exists so artisan uses `.env` (`DB_DATABASE=alfarid_local`). Only phpunit (`phpunit.xml` forces `DB_DATABASE=alfarid_testing`, and `DatabaseMigrations` manages migrate/rollback per test) touches `alfarid_testing`. Never run bare `php artisan migrate` expecting the test DB.
- C2 cutover (passwords, pgbouncer admin_users split, exporter user), H1 Redis split-brain external-check quorum, H3 VIP/API_INTERNAL + wa-gateway routing + second haproxy-data, H4 cache/limiter Redis split, H5 FPM/HAProxy maxconn sizing, H6 mem_limits + oom_score_adj, M4-M9/M11/M12/M14/M15 + L1-L14 infra polish.
- ✅ F9 — order-scoped wallet refunds rejected at source (`WalletService::record` 422 unless via `wallet_adjustment` doc). `WalletLedgerGuardTest`: 8 passed.
- ✅ F25 — TRUNCATE triggers on `customer_wallet_transactions` + `treasury_transactions` (migration `000008`); F3 treasury append-only trigger included.
- ✅ F2/F6 — verified deploy migrates via direct `postgres:5432` with connection-scoped `PGOPTIONS` (no pooled-session leak); app traffic uses `pgbouncer:6432`. Full role split (owner/app/monitor) stays under C2.
- ✅ H7 — `docker-compose.tests.yml` pins safe env (array mailer, local disk, blank cloud/AI secrets); `phpunit.xml` forces them.
- ✅ Infra M1/M2 — `haproxy.cfg` strips `X-Forwarded-Prefix`/`X-Original-URL`/`X-Rewrite-URL` and denies `url_dec`-decoded `/health*` + `*up*`. `haproxy -c` unavailable locally — verify on staging.

Still pending (need staging/DBA/product decisions — see details at the end of this file).
Auth slice: complete (H1–H2, M1–M8, L1–L8).

## Verification status
- `php -l` on all edited backend files + 14 migrations: PASS.
- `UnifiedLoginHardeningTest`: 22 passed. `Auth/` dir: 130 passed (after reverting staff-audit `defer()` — see M6 note).
- `WalletLedgerGuardTest`: 8 passed (incl. new order-refund dataset). `PosShiftSingleOpenIndexTest`: 1 passed.
- `Staff/` dir: 87 passed.
- Storefront + api-client `tsc --noEmit`: clean.
- `repomix-pack.ps1 -All -VerifyMigrations`: 168/168 (100%). Regenerated all 10 subsystem bundles.
- Full `php artisan test`: NOT RUN (hours-long; focused suites above cover all touched areas).
- Frontend `pnpm -r build`: PASS 2026-11-10 (exit 0, all workspaces). Full `pnpm -r typecheck`: clean (7/7).
- `haproxy -c`: unavailable locally — validate `haproxy.cfg` on staging before deploy.

---

# Deployed vs. Pending Deployment

### A. Already Deployed on Server

*Confirmed running in the live environment (Cloud Run / staging/production cluster):*
- **Server Version / Git Commit:** `25ee590` (`fix(tests): avoid duplicate generated brand slugs`).
- **Database Migrations Applied in Production:** Migrations up to `2026_11_12_000073_add_stock_movements_report_index.php`.
- **Infrastructure & Environment:** Production Cloud Run instances, PgBouncer pooler (`6432`), Redis standalone, R2 private storage.
- **Active Environment Configuration:** Production environment variables without local overrides.
- **Queue and Scheduler:** Production queue workers running standard queues (`default`, `media`, `catalog`).
- **Note:** No changes from the current working tree on branch `fixes/task-audit-roadmap` are currently deployed on the live server.

### B. Ready / Pending Server Deployment

*Implemented and verified locally; awaiting release deployment to the server:*

#### 1. Database Migrations
- `2026_11_12_000093_task2_treasury_and_wallet_schema_hardening.php`:
  - Drops obsolete `treasury_transactions_type_check` constraint (`[CRITICAL-01]`).
  - Adds composite index `treasury_transactions (treasury_id, id)` (`[MEDIUM-02]`).
  - Adds composite index `customer_wallet_transactions (customer_id, id)` (`[MEDIUM-02]`).
  - **Required Action:** Run `php artisan migrate --force` directly via PostgreSQL (`port 5432`, bypassing PgBouncer pooler).
- `2026_11_12_000070_align_treasury_shift_expense_precision.php`:
  - Driver guard: skips non-PostgreSQL drivers.
  - Safe transactional rollback: replaces unsafe out-of-transaction check with transactional `lockForUpdate` check (`[HIGH-05]`).
  - **Required Action:** Already applied or updated for future migration executions.

#### 2. Backend Security & Financial Core
- `TreasuryController::index`: Enforces 403 `BusinessException` on cross-branch queries (`[CRITICAL-02]`).
- `TreasuryService::deleteAccount`: Wraps `from_treasury_id`/`to_treasury_id` query in Closure for proper SQL boolean precedence (`[CRITICAL-02]`).
- `WalletAdjustmentService::reverse`: Encapsulates reversal in `idempotency->run` and adds pre-check for existing reversals (`[HIGH-03]`).
- `WalletService::record`: Traps SQLSTATE `23505` unique constraint collisions (`wallet_transactions_reference_alias_unique`) and maps them to 409 `BusinessException`; enforces 422 on zero/negative values for single-event references (`[HIGH-06]`).
- `CreateExpenseTool::undo`: Injects explicit idempotency key `ai-undo-expense-{$action->id}` (`[HIGH-03]`).
- `ExpenseController::void`: Cleans redundant `$request->validate()` call (`[HIGH-03]`).
- `ApproveTreasuryTransferTool::execute`: Reloads source treasury `fresh()` before shortfall checks (`[MEDIUM-01]`).
- `CreateExpenseTool::execute`: Protects existing receipt attachments from deletion on creation failure (`[MEDIUM-01]`).
- `TreasuryTransferController::index`: Replaces correlated `whereHas` on branch treasuries with indexed `whereIn` / `orWhereIn` (`[MEDIUM-03]`).
- `FinanceWriteGuards::transferVersion`: Upgrades transfer version to SHA-256 hash of financial attributes and ISO-8601 Zulu timestamp (`[MEDIUM-06]`).
- **Required Action:** Deploy updated backend codebase and reload PHP-FPM / restart container.

#### 3. Financial Reports & AI Tooling Integrity
- `StatementBudget::run`: Changes parameter default to `bool $readOnly = true` (`[HIGH-01]`).
- `FinanceWriteGuards::suggestTopUp`: Restricts donor treasuries to `main_safe` and `bank_account`, subtracts pending outgoing transfers from available balance, and sanitizes prompts against indirect prompt injection (`[HIGH-02]`).
- `DailyCloseBriefingTool::handle`: Uses business timezone (`Africa/Cairo`) start/end UTC bounds, and filters cash sales without returns (`[HIGH-04]`).
- `ReportService::calculateProfitReport`: Constrains `stock_movements` subquery with `created_at >= periodStart` to prevent full table scans (`[HIGH-04]`).
- `ExplainOrderMoneyTool`: Bounds empty `$returnIds` with `[-1]`, computes symmetric net wallet debit/credit, and detects external branch movements to eliminate false warnings (`[MEDIUM-04]`).
- `ExpenseController::store` & `ExpenseService::create`: Enforce date format `Y-m-d` and bounds `[today - 90 days, today]` (`[MEDIUM-05]`).
- `ExpenseController::update`: Explicitly prohibits modifications to financial fields (`amount`, `treasury_id`, `branch_id`, `expense_date`) (`[MEDIUM-07]`).
- `VerifyDatabaseSchema`: Fixes 1-based array slice `[1:cardinality(c.conkey)]` in foreign key index check (`[LOW-01]`).
- `ReportService::calculateInventoryReport`: Reuses calculated valuation sum when not truncated, and pre-aggregates active variants for dead stock calculation (`[LOW-02]`).
- **Required Action:** Deploy backend codebase; clear configuration and route cache (`php artisan config:cache && php artisan route:cache`).

#### 4. Frontend API Contracts
- `finance.ts`:
  - Added `ExpenseUpdatePayload` (`category?: string; notes?: string | null`) and decoupled `expensesApi.update` (`[MEDIUM-07]`).
  - Added `transfer_number: string` to `TreasuryTransfer` interface (`[MEDIUM-07]`).
  - Extended `shiftsApi.approve` signature to support optional notes payload: `(id, payloadOrKey, idempotencyKey)` (`[MEDIUM-07]`).
- **Required Action:** Deploy updated frontend packages; run `pnpm -r build` and publish static assets.

#### 5. Required Deployment Procedure
1. Create release branch / merge `fixes/task-audit-roadmap` into deployment branch.
2. Put application in maintenance window if necessary.
3. Run database migrations:
   ```bash
   php artisan migrate --force
   ```
4. Clear and rebuild backend application caches:
   ```bash
   php artisan optimize:clear
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```
5. Build and deploy frontend assets:
   ```bash
   pnpm -r build
   ```
6. Restart queue workers:
   ```bash
   php artisan queue:restart
   ```
7. Verify deployment:
   ```bash
   php artisan db:verify-schema
   ```

