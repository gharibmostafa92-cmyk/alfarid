# Task3 Security Roadmap — Customer KYC, Branch Access, Auth & Upload Hardening

Source roadmap: `_archive_unused/tasks_execution/task3.txt` (19 findings:
2 CRITICAL, 6 HIGH, 8 MEDIUM, 3 LOW — customer KYC, staff branch access,
Spatie cache, bcrypt locking, phone/upload validation, OTP races, auth flood,
session middleware, audit gaps, frontend auth/cart, optimistic locking).
Date of Execution: 2026-11-16
Branch: `fixes/task-audit-roadmap`
Status: Implemented & verified locally. This document must NOT be confused
with `docs/TASK3_DEPLOYMENT_STATUS.md`, which tracks the separate wholesale
ledger/pricing roadmap formerly associated with the same filename.

---

# Deployed vs. Pending Deployment

## A. Already Deployed on Server

> [!IMPORTANT]
> Live server deployment status is **unverified / not deployed**. All items
> below were implemented, regression tested (RED → GREEN, one mutation check
> per race fix), and verified locally. None of these changes have been pushed
> or deployed to the production environment.

- **Current Live Server Baseline Commit:** `25ee590` (see `docs/.last-deployed`).
- **Confirmed Live Code:** Pre-task3 security baseline (branch staff can reach
  wholesale legal endpoints; Spatie cache cleared only inside transactions;
  bcrypt runs under the global staff advisory lock; OTP catch is unconditional;
  Turnstile check runs after the users-table lookup; pending customers pass the
  customer middleware; `/api/v1/auth/logout` sits behind `unified.active`).
- **Active Production Migrations:** No new migrations were added by this
  roadmap. Nothing to apply.
- **Active Environment Configuration:** No new environment variables. No
  `.env` changes required.
- **Queue and Scheduler Configuration:** No new queues, workers, or schedules.
  The two customer messaging jobs gained a `backoff()` of `[15, 45]` seconds;
  `php artisan queue:restart` picks it up after deploy.
- **Relevant Infrastructure Changes:** None (application-level only).

## B. Ready / Pending Server Deployment

All 19 roadmap items are implemented and regression tested locally:

### Slice 1 — Branch Access, Permission Cache, Bcrypt, Token Revocation
- **CRITICAL-01:** `assertCentralAccess` on `approve/reject/suspend/reactivate/
  reviewDocument/storeDocument` in `CustomerController`.
- **CRITICAL-02:** `clearPermissionCacheAfterCommit` after `syncRoles` in
  `CreateStaffUser` / `UpdateStaffUser`.
- **HIGH-01:** `StaffAdministration::preVerifyGrantPassword` (bcrypt before the
  transaction/lock; hash-equals inside); `Hash::make` before the transaction in
  `UpdateStaffUser`; wired through all four staff actions.
- **MEDIUM-02:** `UpdateRole` revokes tokens on any permission-set change
  (addition included); `StaffTokenService::pruneAndCap` takes a per-channel
  lifetime (staff/customer/supplier callers pass their own minutes).
- Tests: `Task3Slice1CentralAccessTest` (11/11), plus contract updates in
  `CustomerCoreAndSecuritySuiteTest` (01/05/07/24 green; 11/12 mode matches
  clean tree), `UnifiedAccountActiveTest`, `MultiTableAuthenticationTest`,
  `Task3StaffSliceCTest`.

### Slice 2 — Messaging Races, Auth Flood, Sessions, Lazy Loading
- **HIGH-04:** conditional `code_hash` compare-and-swap in OTP failure path;
  `backoff() = [15, 45]` on both customer messaging jobs; password-reset
  failure now deletes the dead token, refunds budget (`releaseResetClaim`),
  and rethrows for queue retry.
- **HIGH-05:** users-table lookup moved after Turnstile + pair-slot reservation.
- **HIGH-06:** pending customers restricted like rejected (except
  `application-status`, `documents.store`, logout); unified logout reachable
  under `auth:sanctum` alone.
- **LOW-02:** `loadMissing('supplier'/'branch')` in `me()` / resolver.
- Tests: `Task3Slice2SessionMessagingTest` (8/8, mutation-checked).

### Slice 3 — Validation, Uploads, Pruning
- **HIGH-02:** `\A...\z` phone anchors in 9 rule sites; `whatsapp_phone`
  normalization; Enum-tolerant `create`/`approve` comparisons.
- **HIGH-03:** `RejectExecutableSignature` — 1024-byte PDF header scan,
  200-entry cap, macro/external/embeddings/activeX rejection, streamed
  (non-header) size accounting with injectable cap; ObjStm serving note.
- **MEDIUM-03:** `delete($files)` instead of `deleteDirectory`; explicit
  warning when `filesystems.cleanup_owner` is unset.
- **MEDIUM-01:** verified already-fixed (no change).
- Tests: `Task3Slice3ValidationTest`, `Task3UploadValidationTest` (RED
  verified on old rule), `Task3Slice3PruneTest` (mutation-checked),
  existing `TaskPdfUploadRuleTest` green.

### Slice 4 — Frontend, Locking, Audit
- **MEDIUM-04:** `phone-format.ts` (`toAsciiDigits`, byte-identical semantics
  to backend `ArabicDigits`); `PhoneVerification` uses it.
- **MEDIUM-05:** `LoginScreen` probe deps `[view, token, params]`, 800ms →
  2500ms, ref-guarded redirect.
- **MEDIUM-06:** `challengeAttempt` + widget `key`, submit disabled without a
  fresh token, token reset on any failed submit.
- **MEDIUM-08:** `registerWeb` clears the guest cart only on explicit
  `cart_merged === true` (or no session).
- **MEDIUM-07:** AI-conversation 403s are audited (throttle still skipped);
  full request path persisted in denial `via.path`.
- **LOW-01:** `assertVersion` fails closed on null; `DeactivateStaffUser`
  requires a version; AI undo paths pass explicit versions. Documented scope
  decision: no `lock_version` migration for `customers` (no full-snapshot
  writer exists; all current writers are partial; would break API compat).
- **LOW-03:** observational only — Bearer-token architecture retained.
- Tests: `phone-normalization.test.cjs` (new), `auth-registration.test.cjs`
  (+1 regression test, RED verified on old condition),
  `Task3Slice4OptimisticLockingTest` (3/3), `Task3Slice4AuditTest` (2/2);
  `tsc --noEmit` + `eslint` clean on all touched frontend files.

### Pre-existing failures (identical on the untouched tree — NOT regressions)
`pair_budget_remains_strict`, `Task5StaffAdminSlice3Test` role-seeder case,
`concurrent customer registrations…`, `wholesale moves documents…`,
`CustomerCoreAndSecuritySuiteTest` 02/08/09/11/12/17/18/25,
`CustomerVerificationBudgetAndMaintenanceTest` 19/20, `AuthAuditRegressionTest`
wholesale case. Verified via `git stash` A/B comparison during implementation.

---

### Round 2 — Security, Concurrency, Auth & Pruning Hardening (2026-11-17)

All 18 findings in `_archive_unused/tasks_execution/task3.txt` (`CRITICAL-01..02`, `HIGH-01..05`, `MEDIUM-01..08`, `LOW-01..02`, `INFO-01`) are implemented and verified locally, **pending server deployment**:

- **Pending Database Migrations (must run `php artisan migrate --force`):**
  - `2026_11_17_000001_add_lock_version_to_customers_table.php` (adds `lock_version` optimistic concurrency column to `customers`).
  - `2026_11_17_000002_add_prunable_index_to_customer_audit_logs.php` (creates partial index `customer_audit_logs_prunable_created_at_idx` concurrently on PostgreSQL and updates `alfarid_prune_customer_audit_logs` to `ORDER BY created_at, id LIMIT 1000`).
- **Backend Changes:**
  - `CRITICAL-01`: Unverified retail customer emails no longer block staff/supplier creation (`EmailNotUsedByOtherAccountType`, `SerializesAccountEmail` releases unverified retail email with `unverified_email_released` audit), `CustomerAuthService::findForLogin()` scopes unverified fallback to `commercial()`, `StoreCustomerRequest::authorize()` blocks all `CustomerType::commercialValues()` without `customers.approve`.
  - `CRITICAL-02`: `StaffAdministration::mayGrantPermissions()` / `mayManageUser()` pass `'staff'` guard and catch `PermissionDoesNotExist`; `DeactivateStaffUser` checks `assertVersion()` before `! $locked->is_active`; `UpdateStaffUser` skips duplicate `preVerifyGrantPassword()` when `$verifiedPasswordHash !== null`.
  - `HIGH-01`: `CustomerMessageBudget` sorts all `sha256` scope keys deterministically and locks in `ORDER BY key FOR UPDATE` to eliminate deadlocks.
  - `HIGH-02`: `SendCustomerPhoneVerification` & `SendCustomerPasswordReset` set `$tries = 1`; `CustomerAuthService::handleResetDeliveryFailure()` preserves reset tokens on ambiguous read timeouts.
  - `HIGH-03`: `UnifiedAuthService::attempt()` clears `$pairKey` on 403 account-status rejections after valid password authentication.
  - `HIGH-04`: `ValidateCustomerDomains` runs `SET CONSTRAINTS ALL IMMEDIATE` inside `DB::transaction` (pgsql only); `StaffAdministration::lock()` guards on `pgsql` driver.
  - `MEDIUM-01..08`, `LOW-01`, `INFO-01`: Customer `lock_version` concurrency on financial updates, `User::setPhoneAttribute()` + `NormalizeCustomerPhones` Cf-mark normalization, `RejectExecutableSignature` BOM + `/XFA|/GoToR|/GoToE` + `MaxImagePixels` 12,000px dimension cap, `BranchAccess::isBranchActive()` centralization + cache invalidation in `UpdateBranch`, `EnsureWholesaleApproved` single guard lookup, `PruneCustomerArtifacts` expired `personal_access_tokens` batch pruning, `AuditStaffAdministration` GET 403 denial logging, `StaffHandoffService::consume()` fingerprint check before code deletion, `AdminLoginRequest`/`UnifiedLoginRequest` 72-byte `PasswordByteLimit`, `CreateRole`/`UpdateRole` NFKC name normalization, and `WhatsAppIdentityService::revoke()` verified free of HTTP calls inside lock.
- **Frontend & Static Assets (require Next.js / Vite rebuild):**
  - `HIGH-03`, `HIGH-05`, `MEDIUM-07`, `LOW-02`: `LoginScreen.tsx` disables submit on `challengeRequired && !turnstileToken` with `siteKey` retry UI and points probe iframe to static `< 1KB` `apps/backoffice/public/auth/session-probe.html`; `auth.service.ts` hardens `safeLogoutNext()` with `URL` + control-char rejection and preserves retail `redirect` in `registerWeb()`; `AccountForm.tsx` adds live 72-byte password check; `RequireActor.tsx` uses key-order-independent `recordsEqual()`.

---

## Required Production Deployment Steps

When authorized to deploy to the live server, execute in order:

### 1. Pre-Deployment Database Backup
```bash
pg_dump -U postgres -d alfarid_production -F c -b -v -f /backups/alfarid_pre_task3sec_$(date +%Y%m%d_%H%M%S).dump
```

### 2. Git Pull Code
```bash
git pull origin fixes/task-audit-roadmap
```

### 3. Database Migrations
None required by this roadmap (no new migration files). Still run the
standard check:
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
Loads the new job `backoff()` behavior:
```bash
php artisan queue:restart
```

### 6. Build & Deploy Frontend Assets
```bash
pnpm --filter @alfarid/storefront build
```

### 7. Post-Deployment Verification Checklist
1. Branch-restricted staff gets 403 on `POST /api/v1/admin/customers/{id}/approve`.
2. Central staff approve flow still returns 200.
3. Pending wholesale customer gets 403 on `/api/v1/store/account/orders` but
   200 on `/api/v1/wholesale/auth/application-status`.
4. Suspended customer `POST /api/v1/auth/logout` returns 204 and revokes the token.
5. `php artisan customers:prune-artifacts` warns when `R2_CLEANUP_OWNER` is unset.
6. Storefront registration with Arabic-Indic digits verifies the phone.
7. Run the backend slice suites: `Task3Slice1CentralAccessTest`,
   `Task3Slice2SessionMessagingTest`, `Task3Slice3ValidationTest`,
   `Task3Slice3PruneTest`, `Task3Slice4OptimisticLockingTest`,
   `Task3Slice4AuditTest`, `Task3UploadValidationTest`.

---

# Run 2026-11-17 — Cross-Realm Identity, Token Scope, Budgets, Upload & Frontend Hardening

Source roadmap: `_archive_unused/tasks_execution/task3.txt` (same file; item
IDs below are the `[CRITICAL-XX]` / `[HIGH-XX]` / `[MEDIUM-XX]` / `[LOW-XX]`
headers in that file, located by header — not by line number, which shifts as
the roadmap ledger at the top of the file is updated).

Branch: `fixes/task-audit-roadmap`. Status: implemented & verified locally;
**not deployed** (no production access; nothing was pushed, migrated, or
restarted outside the local checkout and the local `alfarid_testing` database).

## Deployed vs. Pending Deployment (this run)

### A. Already Deployed on Server

No change from the 2026-11-16 baseline: live status is **unverified / not
deployed**. Nothing in this run was pushed to production.

### B. Ready / Pending Server Deployment (this run)

All items below are implemented and covered by targeted tests. Only item 5
touches an existing migration file (no new migration files were added);
the scheduler gains one weekly entry; new `auth.customer_sms_reset_*`
config keys all carry inline defaults, so no `.env` change is required
(set them explicitly to tune the 30% bulkhead and subnet caps).

- **CRITICAL-01** — cross-realm email shadowing closed: `EmailNotUsedByOtherAccountType`
  checks every live customer row regardless of `email_verified_at`;
  `SerializesAccountEmail` no longer skips unverified non-commercial accounts.
  Tests: `Task3Critical01CrossRealmTest` (3/3) + `EmailUniquenessTest` (8/8).
- **CRITICAL-02** — one advisory-lock key for all identity writes:
  new `App\Support\AccountEmailLock` (`account_email:` + normalized email,
  pgsql-guarded, transaction-required); all five call sites unified.
- **HIGH-01** — `deleteStaleResetToken()` compare-and-delete in the reset
  delivery-failure path. Tests: `Task3PasswordResetHardeningTest` (4/4).
- **HIGH-02** — reset-SMS bulkhead (30% of global), per-subnet day/hour caps
  (fail-closed), pinned `$budgetAt` passed through claim → refund, `networkKey`
  plumbed from `forgotPassword` through `SendCustomerPasswordReset` (optional
  3rd ctor arg, queued-job compatible). Tests: `Task3ResetBudgetIsolationTest` (4/4).
- **HIGH-03** — `SessionProbePage` queries `/api/v1/auth/me` (works with `*`
  and `pos` tokens; returns the `redirect.path` the probe reads).
  Test: `session-probe.test.cjs` (2/2).
- **HIGH-04** — `EnsureWholesaleApproved` requires a wholesale-scoped token;
  `approve()` revokes all tokens when the customer type changes.
  Tests: `Task3ChannelTokenScopeTest` (5/5, merge guard RED-verified).
- **HIGH-05** — shared `App\Rules\PdfHeader` (1024-byte window, whitespace/BOM
  preamble only); `#`-only active-content scan; single-trip `downloadDocument`
  (`readStream` + 404, no `exists()` HEAD). Tests: `Task3PdfHeaderParityTest`
  (4/4), `Task3UploadValidationTest` (6/6), `Task3DocumentDownloadTest` (3/3).
- **HIGH-06** — narrow-only `$abilities` in `StaffTokenService::issue`;
  verified self-edits keep the current token; optional `expected_updated_at`
  on financial customer fields. Tests: `Task3StaffSessionLifecycleTest` (5/5).
- **MEDIUM-01** — atomic `RateLimiter::hit($pairKey)` moved before the
  Turnstile challenge. Test: `Task3RateLimitOrderingTest` (1/1).
- **MEDIUM-02** — 422 `phone already registered` after Turnstile on
  `phone-verification/send` (job path stays silent). Test: `PhoneRegistrationTest` (6/6).
- **MEDIUM-03** — `\p{Cf}`/Bidi strip in `Customer::normalizePhone`,
  `CustomerChannel::canonicalPhone`, and `phone-format.ts`.
  Tests: `Task3PhoneNormalizationTest` (3/3), `phone-format-bidi` (3/3),
  `phone-normalization` (5/5).
- **MEDIUM-04** — guest-cart merge restricted to retail (`UnifiedAuthController`);
  unified-login documented as staff+customers (supplier portal separate);
  storefront clears on `cart_merged === true` with customer-only actorType.
- **MEDIUM-05** — `RequireActor` reuses `unauthorizedLoginRedirect` (search
  preserved, single converged URL); `LoginScreen` restores wholesale/supplier
  deep links via `safeReturnPath` channel roots. Tests: `safe-return-path`
  (17/17), `require-actor` contract tests, `auth-redirect` green.
- **MEDIUM-06** — `customers.approve` admitted to document middleware;
  `$request->user('staff')` unified; per-network audit guest buckets.
  Tests: `Task3DocumentDownloadTest` (+approver case), `Task3AuditGuestBucketTest`.
- **MEDIUM-07** — `SET LOCAL lock_timeout = '3s'` around ALTER in migration
  `2026_11_05_000002` and `customers:validate-domains` (tested live);
  `NormalizeCustomerPhones` batch/abort semantics kept (pinned by `test_26`).
- **MEDIUM-08** — `InventoryPermissionSeeder` + registrar flush inside
  `RolePermissionSeeder` (verified by standalone seed + `DatabaseSeederTest`).
- **LOW-01** — 72-byte ceiling in `resetPassword` + `registerRetail`
  (same test file as HIGH-01).
- **LOW-02** — accepted as-is per the roadmap's own trade-off clause (direct
  PK lookup kept; no change).
- **LOW-03** — hourly cheap pass + `customers:prune-artifacts --deep` with a
  weekly Sunday 05:15 schedule; `test_27` updated to the split contract.

### Deployment deltas introduced by this run

1. Scheduler: `customers:prune-artifacts --deep` weekly on Sunday 05:15
   (Africa/Cairo) in `bags-platform/backend/routes/console.php`.
2. Optional config overrides (defaults work without them):
   `auth.customer_sms_reset_fraction` (0.3),
   `auth.customer_sms_reset_network_daily_limit` (10),
   `auth.customer_sms_reset_network_hourly_limit` (3).
3. Migration file `2026_11_05_000002_enforce_customer_identity_indexes.php`
   edited (lock timeout only; idempotent for fresh installs).
4. No new migrations, no `.env` secrets, no production actions taken.

### Pre-existing failures (verified unrelated — do NOT gate deployment)

`UnifiedLoginTest::guest_cart_merges_into_customer_account_on_login`
(draft-variant scope), `Task4AuthPasswordResetTest` phone x2 (SMS unconfigured
in `.env.testing`), `Task3Slice2SecurityTest` pending-trader code,
`UnifiedLoginHardeningTest` x3 (missing cart header, redirect drift,
branch-access setup), `CustomerAbuseProtectionTest::audit…`,
`AdminCustomerManagementTest` approve/reject/suspend x3 (all three are the
same `inventory.all_branches` setup gap, proven by isolated probes reaching
the service layer), `CustomerVerificationBudgetAndMaintenanceTest` test_19
(`send()` to soft-deleted phones delivers — no `withTrashed` in the
existence check; service untouched by this run) and test_20 (0-byte fake PDF
with reported PDF mime rejected by old and new header rules alike). Storefront `tsc --noEmit` has pre-existing wholesale-page
prop errors in files this run did not touch; all touched files typecheck clean.
