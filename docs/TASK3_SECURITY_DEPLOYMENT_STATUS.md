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
