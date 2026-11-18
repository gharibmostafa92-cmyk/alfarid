# Module 01: Foundation & Authentication (`01-auth`)

**Scope:** Customer self-registration (`/auth/register`), unified login (`/auth/login` across retail, wholesale, staff/admin/POS, and supplier), guest-cart merge on login/register, password reset (`/auth/forgot-password`, `/auth/reset-password`), role/status guards, rate limiting, and logout.

---

### `HT-AUTH-01`: Retail Customer Self-Registration & Guest Cart Merge
- **Preconditions:** Logged out. Storefront running (`http://localhost:3000` or configured storefront URL). Add at least 1 in-stock item to the guest cart before registering.
- **Steps:**
  1. Navigate to `/auth/register`.
  2. Enter a valid name, an unused Egyptian mobile number matching `01[0125]XXXXXXXX`, an optional unused email, and a password of 8–72 bytes.
  3. (If `phone_verification_required` is enabled in `GET /api/v1/store/auth/registration-options`, complete the phone OTP + Turnstile verification step.)
  4. Submit the registration form.
- **Expected Result:** Account is created (`201`), user is logged in and redirected to the storefront/account, and the guest cart item(s) added prior to registration are preserved/merged into the new customer's cart.
- **Status:** `PENDING`
- **Observed:**

---

### `HT-AUTH-02`: Registration Validation — Duplicate Phone, Cross-Account Email & Invalid Inputs
- **Preconditions:** Logged out. Have an existing customer phone/email and (if available) an existing staff or supplier email.
- **Steps:**
  1. On `/auth/register`, try submitting a non-Egyptian phone (e.g., `0212345678` or `01912345678`) and a short password (`1234567`).
  2. Fix those and submit using an already-registered customer phone number.
  3. Change the phone to a fresh `010...` number and submit using an email already belonging to another customer, or to a staff/supplier account.
- **Expected Result:**
  1. Client/server rejects invalid phone format (`01[0125][0-9]{8}`) and password < 8 chars (or > 72 bytes).
  2. Duplicate customer phone returns a 422 validation error without creating a duplicate account.
  3. Duplicate customer email or email used by another account type (`EmailNotUsedByOtherAccountType`) is rejected with a validation error.
- **Status:** `PENDING`
- **Observed:**

---

### `HT-AUTH-03`: Unified Login — Retail Customer vs. Wholesale Customer Routing & Cart Merge
- **Preconditions:** Logged out. Add an item to the guest cart. Have credentials for (a) an approved retail customer and (b) an approved wholesale customer.
- **Steps:**
  1. Go to `/auth/login`, sign in with the **retail** customer (using email or phone). Observe redirect and cart contents.
  2. Log out, add an item to the guest cart again, and sign in on `/auth/login` with the **approved wholesale** customer.
- **Expected Result:**
  - Retail login issues `store-token`, redirects to storefront/account, and merges the guest cart.
  - Wholesale login issues `wholesale-token`, redirects to the wholesale portal (`/wholesale/...`), and does **not** merge/consume the retail guest cart.
- **Status:** `PENDING`
- **Observed:**

---

### `HT-AUTH-04`: Unified Login — Staff Handoff to Backoffice / POS
- **Preconditions:** Logged out. Have credentials for an active Admin/Backoffice user and an active POS Cashier user.
- **Steps:**
  1. On `/auth/login`, sign in with the Admin/Backoffice staff account.
  2. Observe the handoff redirect to the Backoffice app (`/handoff` / `handoff/exchange`) and verify the Backoffice dashboard loads with user info.
  3. Log out, return to `/auth/login`, and sign in with the POS Cashier account.
- **Expected Result:**
  - Staff login returns a one-time `handoff_code` and redirects to the appropriate app (Backoffice for admin/manager, POS for cashier).
  - Exchanging the `handoff_code` issues a Sanctum token; refreshing the target app keeps the session active via `/api/v1/auth/me`.
  - Attempting to reuse the same `handoff_code` fails with 401 (`رابط الدخول غير صالح أو انتهت صلاحيته.`).
- **Status:** `PENDING`
- **Observed:**

---

### `HT-AUTH-05`: Staff Guardrails — Temporary Password & Inactive Branch Block
- **Preconditions:** Staff account with `must_change_password = true`, and a non-SuperAdmin staff account assigned to an inactive branch (if available in test data).
- **Steps:**
  1. Log in as the staff user with `must_change_password = true` and try navigating to admin pages.
  2. Log in as the staff user assigned to an inactive branch.
- **Expected Result:**
  1. `/api/v1/auth/me` returns 403 `password_change_required` (`يجب تغيير كلمة المرور المؤقتة قبل المتابعة.`), forcing password rotation via `PUT /api/v1/admin/me/password` before accessing backoffice routes.
  2. Inactive-branch staff receives 403 (`الفرع المرتبط بحسابك متوقف حالياً.`).
- **Status:** `PENDING`
- **Observed:**

---

### `HT-AUTH-06`: Suspended / Deactivated Account & Logout Revocation
- **Preconditions:** A suspended customer account (`status = suspended`) and/or deactivated staff/supplier user.
- **Steps:**
  1. Attempt to log in with the suspended customer or deactivated staff/supplier credentials.
  2. If a token was active before suspension, call `/api/v1/auth/me` and then trigger Logout (`POST /api/v1/auth/logout`).
- **Expected Result:**
  - Login or `/auth/me` is rejected with 403.
  - Logout (`POST /api/v1/auth/logout`) still succeeds (`204 No Content`) and revokes the token even when the account is suspended/deactivated.
- **Status:** `PENDING`
- **Observed:**

---

### `HT-AUTH-07`: Forgot Password & Reset Password Flow
- **Preconditions:** Logged out. An existing retail customer account with a known email or phone.
- **Steps:**
  1. Go to `/auth/forgot-password` and submit the registered email (and test submitting an unregistered email/phone to check for user enumeration).
  2. Retrieve the reset link/token (from mailhog/logs/notification) and open `/auth/reset-password`.
  3. Try submitting mismatched passwords or a password < 8 chars, then submit a valid new password + confirmation.
  4. Sign in at `/auth/login` with the old password (should fail) and then the new password (should succeed).
- **Expected Result:**
  - Forgot-password returns the same generic message (`لو الحساب موجود، أرسلنا رمز الاستعادة إليه.`) regardless of whether the account exists.
  - Reset-password validates `min:8`, `max:72`, `confirmed`, and 64-char `token`; after success, old password fails and new password logs in.
- **Status:** `PENDING`
- **Observed:**

---

### `HT-AUTH-08`: Login Rate Limiting & Brute-Force Protection
- **Preconditions:** Logged out.
- **Steps:**
  1. On `/auth/login`, submit wrong passwords repeatedly (> 10 times) for a test identifier from the same browser/IP.
- **Expected Result:** After hitting the soft pair limit (`unified_login_failures_hour = 10`), the API requires a Turnstile challenge (`429`, `challenge_required`: `أكمل التحقق البشري للمتابعة.`) or blocks excessive attempts (`محاولات دخول كثيرة؛ حاول لاحقًا.`) rather than allowing unlimited brute force.
- **Status:** `PENDING`
- **Observed:**

## QA run — 2026-11-19

Playwright drove the local storefront and Backoffice against Laravel with the
`alfarid_testing` PostgreSQL database. Password recovery captured the SMS in the
browser harness. Live SMS delivery and Turnstile verification were outside the run.

| Case | Observed |
| --- | --- |
| HT-AUTH-01 | Retail registration returned 201 and redirected to the shop in Playwright. A Laravel feature test added an in-stock item through the cart API and verified that registration preserved its quantity and consumed the guest cart. |
| HT-AUTH-02 | API returned 422 for an invalid Egyptian phone, a short password, a duplicate customer phone and a staff email. The invalid and duplicate phone requests created no accounts. |
| HT-AUTH-03 | Unified retail login worked in Playwright. Stock-backed API tests verified retail merge and wholesale preservation of the guest cart. The approved wholesale redirect was checked at the API level. |
| HT-AUTH-04 | Backoffice dashboard loaded in Playwright and exchanged the code once. The ordinary single-use exchange test passed. A separate context-binding assertion failed with 200 where it expected 401. POS browser handoff and Backoffice refresh were not covered by this run. |
| HT-AUTH-05 | Temporary password rotation passed at the API level. The combined staff guard test failed on the POS response shape because it included permissions. |
| HT-AUTH-06 | Suspended customer and disabled staff access checks and logout revocation passed at the API level. |
| HT-AUTH-07 | Playwright requested a recovery code, reset the password and signed in with the new password. SMS was captured locally. Rejection of the old password was not asserted by that browser case. |
| HT-AUTH-08 | API returned 429 on repeated invalid credentials. The case covering repeated requests missing a required Turnstile response did not pass. |

The browser suite finished with 3 of 4 journeys passing. Wholesale application
registration returned 422 with a validation error on `documents.0` after a PDF
was selected. Existing backend runs finished with 54 of 62 and 75 of 82 tests
passing. Both backend runs included failures and errors. The three new
stock-backed cart cases and both registration phone validation cases passed.
`StoreAuthTest.php` and `PhoneRegistrationTest.php` could not load due to
unresolved merge markers. The suite for the current supplier contract expects
supplier login to be retired, while the module scope lists it as supported.
