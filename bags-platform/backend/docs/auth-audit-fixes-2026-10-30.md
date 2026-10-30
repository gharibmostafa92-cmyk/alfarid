# Authentication audit fixes — 2026-10-30

Scope: the supplied H1–H4, M1–M7 and L1–L9 findings. Per the user's instruction, registration continues to work without mandatory phone/email verification. Existing verification remains optional and does not mark an unverified contact as verified.

| Finding | Resolution |
| --- | --- |
| H1 | Identifier hourly quota is checked after a failed credential check. Valid credentials survive hourly/daily/global quotas; the per-network/identifier quota remains strict. |
| H2 | Verification consumes at most 60% of hourly/daily SMS capacity. Recovery retains at least 40%. The encrypted verification job carries a network HMAC; each network has a configurable verification daily budget. Exhaustion logs alongside the existing 80% warning. |
| H3 | Concurrent partial `LOWER(customers.email)` index matches the non-deleted login query. Staff email gets a case-insensitive unique index. |
| H4 | POS group requires `pos.access`; returns and exchanges additionally require `pos.sales.refund`. |
| M1 | New customer registrations reject existing customer/staff/supplier emails case-insensitively. Staff/supplier creation also recognizes unverified trader emails as login identities. Existing duplicate contacts are preserved and remain fail-closed for ambiguous login. |
| M2 | The customer recovery UI requests a registered phone number. The backend still supports verified email recovery for existing verified accounts. No new email verification flow is imposed. |
| M3 | Registration and legacy customer login report cart merge failures, preserve successful authentication, and return `cart_merged=false`. The frontend retains the guest cart session for retry. |
| M4 | PostgreSQL sequence ID is reserved before registration; document moves run before the DB transaction. The submitted event dispatches after commit. Notification/staging-cleanup failures are reported without deleting committed documents or failing successful registration. Failed registration cleans uploaded files; the existing orphan sweep remains the fallback. |
| M5 | Intentionally skipped: mandatory verification before account creation, its default change and production boot guard. |
| M6 | Middleware, login service and verification jobs share `NetworkIdentity`: IPv6 `/64`, IPv4 unchanged, IPv4-mapped IPv6 shares its IPv4 bucket. |
| M7 | Legacy POS login checks permission, system-account exclusion and branch activity; uses the destination resolver and `StaffTokenService` for expiry and token limits. |
| L1 | Removed ineffective legacy controller limiter clears. |
| L2 | Both customer rehash paths compare the previously checked hash before replacement; a concurrent password reset wins and stale login is rejected. |
| L3 | Staff email index migration preflights case-insensitive duplicates and aborts rather than choosing/deleting accounts. |
| L4 | New phone OTP challenges use keyed HMAC with constant-time comparison, bound to the phone key. Existing bcrypt challenges remain readable during their ten-minute lifetime. Expired/missing/consumed challenges avoid bcrypt work. |
| L5 | Message counters widen to integer. Rollback preserves the wider type to avoid truncating existing counts. |
| L6 | Wholesale registration/reset requires 12 characters. Customer registration/reset rejects passwords over 72 bytes, including multibyte input. Existing passwords still authenticate. |
| L7 | The existing Next session endpoint requires JSON, rejects foreign Origin/cross-site requests, validates token syntax and bounds expiry to 1–7200 seconds. |
| L8 | Egyptian mobile `201…` input normalizes to local `01…`, including in the normalization command. |
| L9 | Concurrently removes redundant `customers_phone_index`, retaining the phone unique constraint. |

## Informational observations

- I1: Unified login deliberately excludes historical supplier identities. Supplier login remains available only when `LEGACY_LOGIN_ENABLED=true`; dealers use customer accounts. No historical supplier APIs/accounts are deleted.
- I2: MFA for administrators remains an architectural follow-up; this patch adds no new authentication dependency.
- I3: Staff middleware now enforces token scope: wildcard tokens retain role-based access, historical `pos` tokens can reach POS only. Route permissions apply in addition to token scope.
- I4: Scheduled `sanctum:prune-expired --hours=24` daily with overlap/single-server protection.
- I5: Existing localStorage authentication remains. Moving all applications to cookie sessions and tightening their CSP requires a coordinated session migration and remains a follow-up.
- I6: The production environment example already selects Redis for `CACHE_STORE` and `CACHE_LIMITER`; database cache remains supported. No infrastructure deployment is performed here.
- I7: Known staff login successes/failures now persist in the existing `staff_audit_logs`, including legacy admin/POS login. Unknown identifiers do not generate unbounded audit rows. Credentials and email addresses are not stored in audit metadata.
- I8: Phone normalization wraps temporary-table use in one transaction with `ON COMMIT DROP`, pinning it to one backend connection under transaction pooling.

## Deployment and compatibility

Run the two `2026_10_30` migrations during deployment. The lookup migration runs outside a transaction, uses concurrent indexes, and rebuilds an invalid index left by an interrupted attempt. Resolve any duplicate staff emails reported by preflight before retrying; no automatic account deletion occurs. Existing duplicate customer emails are not rewritten.

`CUSTOMER_SMS_VERIFICATION_FRACTION` defaults to `0.6` and is capped at `0.6`. `CUSTOMER_SMS_VERIFICATION_NETWORK_DAILY_LIMIT` defaults to `10`; adjust for expected CGNAT traffic. Older queued verification jobs without a network key retain global and recipient limits during rollout. Existing bcrypt OTP challenges remain valid until expiry. New settings are included in both environment examples.

Customer email uniqueness avoids a unique index that would fail on historical duplicate contact emails. Retail and wholesale self-registration now reserve the normalized email with the existing PostgreSQL advisory-lock key and recheck both customer uniqueness and cross-account validation inside the registration transaction. Two concurrent self-registrations can therefore create only one customer; the other receives an email validation error (422), and rejected wholesale uploads are cleaned up. Historical duplicate contacts and the existing rules for unverified retail contacts remain unchanged. Direct imports and other account-writing workflows still need their own applicable identity rules.

Next.js session handler APIs were checked against the official [NextRequest](https://nextjs.org/docs/15/app/api-reference/functions/next-request) and [NextResponse](https://nextjs.org/docs/15/app/api-reference/functions/next-response) references for the installed Next.js 15 release; this package does not include `dist/docs`.

## Verification

Regression tests cover soft hourly lockout, reserved SMS capacity, per-network SMS budgets, IPv6/IPv4-mapped identities, cart-merge failure, HMAC and legacy OTPs, POS permission/scope boundaries, legacy POS expiry/token limits, staff audit persistence, multibyte bcrypt limits, counters above 32767, storage I/O outside the registration transaction and concurrent password-reset/rehash protection.

Frontend tests cover guest-cart preservation and phone recovery. The isolated Edge browser test exercises the live recovery UI and session endpoint JSON/origin/expiry/cookie behavior. TypeScript checking and PHP Pint run against the affected code. Final execution results are recorded with the task delivery.

Observed checks: storefront TypeScript passed; 8 frontend unit tests passed; isolated live Edge browser checks passed; affected PHP files passed Pint. Backend final regression-suite result is reported in the delivery message. The complete repository suite was started, then stopped for the final auth-focused rerun after further changes; no full-repository pass is claimed.

Final backend verification: the expanded affected suite executed 261 tests (260 passed; the remaining failure was a new test counting all users instead of the duplicate-email subset, because migrations already create a system user). After correcting that fixture assertion, both auth index migration tests passed (9 assertions). The focused 82-test auth/customer review run also passed (329 assertions). No application-code failures remained in the affected suite.
