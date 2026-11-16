# Task5 — Auth, Identity, KYC & Session Security Roadmap

Source roadmap: `_archive_unused/tasks_execution/task5.txt` (23 items: 3 Critical, 6 High, 10 Medium, 3 Low, 1 Info).
Date of Execution: 2026-11-16
Status: 100% Implemented & Verified in local repository (`_archive_unused/tasks_execution/task5.txt` all marked `✅`).

---

# Deployed vs. Pending Deployment

## A. Already Deployed on Server

> [!IMPORTANT]
> Live server deployment status is **unverified / not deployed**. No live server access was used and no production databases or services were migrated or restarted. All changes below are implemented and verified locally only.

- **Current Live Server Baseline:** Pre-execution state (refer to `docs/.last-deployed`; live server state unverified).
- **Applied Database Migrations:** Migration `2026_11_15_000008_harden_customer_audit_prune_and_token_index.php` is **not** yet applied on production.
- **Note on Existing `docs/TASK5_DEPLOYMENT_STATUS.md`:** That document tracks an earlier POS/Inventory Task5 roadmap (`task5.txt` dated 2026-11-13); this document tracks the Auth/Identity/KYC/Session Security roadmap in `_archive_unused/tasks_execution/task5.txt`.

---

## B. Ready / Pending Server Deployment

### 1. Critical Findings (3 Items)

- **`[CRITICAL-01]` Customer Audit Log Retention Floor & `TRUNCATE` Revocation:**
  - Added `bags-platform/backend/database/migrations/2026_11_15_000008_harden_customer_audit_prune_and_token_index.php` updating `public.alfarid_prune_customer_audit_logs(timestamptz)` with `SET search_path = pg_catalog, pg_temp`, enforcing `LEAST(cutoff, now() - interval '90 days')`, and revoking `UPDATE, DELETE, TRUNCATE` on `public.customer_audit_logs` from `alfarid_app`.
- **`[CRITICAL-02]` Token Type & Ability Isolation (`EnsureCustomerAuthenticated` & `UnifiedAuthController::me`):**
  - Enforced `$token instanceof PersonalAccessToken` and `store-token`/`wholesale-token` abilities in `EnsureCustomerAuthenticated`.
  - Enforced `PersonalAccessToken`, `must_change_password`, active branch check, and POS token payload trimming (omitting `roles`/`permissions`) in `UnifiedAuthController::me`.
- **`[CRITICAL-03]` KYC Document Upload & Download Hardening:**
  - Updated `MaxImagePixels` to skip `%PDF-` files safely and `RejectExecutableSignature` to reject `PK\x03\x04` archives and PDFs containing `/JavaScript`, `/JS`, `/Launch`, `/EmbeddedFiles`, or `/OpenAction`.
  - Applied `MaxImagePixels` and `RejectExecutableSignature` in `CustomerController::storeDocument` and forced explicit `Content-Type`, `X-Content-Type-Options: nosniff`, and MIME-matched `Content-Disposition: attachment` in `CustomerController::downloadDocument`.

### 2. High Findings (6 Items)

- **`[HIGH-01]` Email Uniqueness Consistency:** Aligned `UniqueCustomerEmail` with `EmailNotUsedByOtherAccountType` and DB partial unique indexes (unverified retail emails cannot squat/block wholesale or staff registrations).
- **`[HIGH-02]` Soft-Deleted Customer Restore Phone Collision:** Added pre-restore active phone check and `UniqueConstraintViolationException` catch in `CustomerManagementService::restore` (returning controlled 422 `BusinessException`).
- **`[HIGH-03]` Turnstile Timeout & CGNAT Hard-Lock Protection:** Added `connectTimeout(1)->timeout(2)` and 30s consumed-token caching in `TurnstileToken`; deferred `RateLimiter::hit($pairKey)` in `UnifiedAuthService::attempt` until after Turnstile validation so missing-token prompts do not burn the 5x hard lock.
- **`[HIGH-04]` `UpdateStaffUser` Lock Ordering & Case-Insensitive Email Validation:** Acquired `pg_advisory_xact_lock` on new email, used `lockForUpdate()` on `Branch`, caught `UniqueConstraintViolationException`, and made `UpdateUserRequest` email uniqueness case-insensitive (`LOWER(email)`).
- **`[HIGH-05]` `StaffHandoffService` Single-Use Burn & Dual-Stack Tolerance:** Burned `handoff_code` immediately on first `consume()` attempt (`Cache::pull`) even on fingerprint mismatch and tolerated IPv4/IPv6 Dual-Stack IP switches while checking `User-Agent`.
- **`[HIGH-06]` Phone Verification Login Enforcement & `CustomerChannel` Revocation:** Enforced `auth.require_phone_verification` on `CustomerAuthService::login` and deleted `customer_channels` rows on customer `update` (phone/`whatsapp_phone` change), `suspend`, and `delete`.

### 3. Medium, Low & Info Findings (14 Items)

- **`[MEDIUM-01]` & `[MEDIUM-02]`:** Verified `UpdateSettingsRequest` Arabic messages and `NormalizeCustomerPhones` `translate()` string; added `+2001...`/`002001...` double-zero prefix normalization to `Customer::normalizePhone`, `NormalizeCustomerPhones`, and `PhoneVerification.tsx`.
- **`[MEDIUM-03]`:** Removed 10-minute post-verification resend block in `CustomerPhoneVerificationService::send`, reset `created_at` on SMS failure, and set `$tries = 3` on `SendCustomerPhoneVerification` & `SendCustomerPasswordReset`.
- **`[MEDIUM-04]` & `[INFO-01]`:** Added `->whereNull('deleted_at')` to `Rule::unique('customers', 'phone')` in `StoreCustomerRequest` and `UpdateCustomerRequest`.
- **`[MEDIUM-05]`:** Removed dead `$selfRotation` path and redundant `Hash::make` in `UserController::update`/`updateOwnPassword`; set `minLength={wholesale ? 12 : 8}` in `AccountForm.tsx`.
- **`[MEDIUM-06]`:** Added `JSON_INVALID_UTF8_SUBSTITUTE` to `StaffHandoffService::credentialFingerprint`; cleaned up `LoginScreen.tsx` iframe/listener on 800ms timeout and typing; stabilized `allowKey` and avoided redundant `setSession` writes in `RequireActor.tsx`.
- **`[MEDIUM-07]`:** Added `approve` and `documents.view` to `RolePermissionSeeder` under `customers` and switched `CustomerController` middleware to Spatie `permission:*|customers.manage`.
- **`[MEDIUM-08]`:** Added composite index `personal_access_tokens_tokenable_id_desc_idx` in migration `2026_11_15_000008` and simplified `StaffTokenService::pruneAndCap` to an atomic `whereNotIn` delete.
- **`[MEDIUM-09]`:** Expanded `AuditStaffAdministration` regex to include `customers` and `suppliers`, and mapped `ai` to `ai_administration`.
- **`[MEDIUM-10]`:** Made `revokePreviousSession` non-blocking for SPA logins/registrations and 1500ms-bounded for backoffice handoff in `auth.service.ts`.
- **`[LOW-01]`:** Added `min:2` to `search` in `UserController::index`.
- **`[LOW-02]`:** Deferred `Notification::sendNow` via `defer(...)->always()` in `AuthenticationAlerts::report`.
- **`[LOW-03]`:** Added `.finally(() => setBusy(false))` in `AccountMenu.tsx`.

---

## C. Required Operational Deployment Steps

1. **Database Migrations (Migration-Owner Role):**
   ```bash
   php artisan migrate --force
   ```
   Applies `2026_11_15_000008_harden_customer_audit_prune_and_token_index.php`.
2. **Permissions Seeder:**
   ```bash
   php artisan db:seed --class=RolePermissionSeeder --force
   ```
   Ensures `customers.approve` and `customers.documents.view` exist in `permissions` and are synced to `Super Admin`.
3. **Backend & Frontend Build / Redeploy:**
   - Rebuild & deploy backend API container (`alfarid-api`) and queue workers (`SendCustomerPhoneVerification`, `SendCustomerPasswordReset`).
   - Rebuild & deploy `@alfarid/storefront` (`pnpm --filter @alfarid/storefront build`).
