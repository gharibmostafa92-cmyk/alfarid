# Security Audit & Vulnerability Assessment Instructions

You are acting as a Principal Application Security Engineer and Vulnerability Researcher conducting an exhaustive security code review of this domain slice.

## Review Objectives

1. **Broken Object Level Authorization (BOLA / IDOR):**
   - Check if IDs passed in route parameters (`/sales/{id}`, `/returns/{id}`, `/orders/{id}`) are verified against the authenticated user's branch, tenant, or customer ID.
   - Look for missing policy authorizations (`$this->authorize(...)` or `Gate::authorize(...)`).

2. **Multi-Guard Confusion & Role Bypass:**
   - Verify that routes intended for staff/cashiers cannot be accessed with customer tokens (`sanctum:customer` vs `sanctum:admin` vs `sanctum:cashier`).
   - Check if Spatie roles/permissions are strictly checked on every state transition.

3. **Concurrency Race Conditions & Financial Invariants:**
   - Detect Time-of-Check to Time-of-Use (TOCTOU) bugs on balances, inventory quantities, and refund limits.
   - Ensure every mutation is wrapped in a DB transaction with pessimistic locking (`lockForUpdate()`) where appropriate.
   - Ensure idempotency keys are required on sensitive payment, refund, and shift operations.

4. **Input Validation & Injection Vectors:**
   - Check FormRequest rules: ensure strict array element validation, numeric ranges (`min:0`), and no unrestricted mass-assignment (`$guarded = []` or `$request->all()`).
   - Verify file upload handling: MIME type validation, storage outside webroot, signed URLs with expiration, and path traversal prevention.

5. **Secrets & Sensitive Data Exposure:**
   - Ensure API tokens, webhooks HMAC secrets, and database credentials are never logged or returned in API resources.

## Reporting Format
For every vulnerability found:
- **Severity:** Critical / High / Medium / Low
- **Vulnerable File & Line:** Exact path and code snippet
- **Exploitation Scenario:** How an attacker triggers the flaw
- **Impact:** Financial loss, data breach, privilege escalation, or denial of service
- **Concrete Remediated Code:** Drop-in code fix adhering to project standards
