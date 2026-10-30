# Wholesale portal integration review

Reviewed against `/task.txt` on 2026-10-29. Continued the existing backend/API/screen integration in place. Existing storefront changes were preserved. No CSS, class names, or files in `new_design/` were changed.

## Completed corrections

- Account and cart loading now expose errors and retry instead of indefinite skeletons or a false empty cart. Application status does not depend on approved-only dashboard or cart requests.
- A scoped, cleaned-up Axios interceptor handles wholesale 403 responses from reads, mutations, polling, and downloads.
- Invoice, statement, checkout preview, and quick lookup mappers convert Laravel decimal strings before rendering. Statement date shortcuts use local calendar dates.
- Checkout clears the previous shipping preview during a request, disables submission until the selected payment method has a current preview, displays general API errors, and preserves the idempotency key when confirming changed prices.
- Reorder reuses its mutation during React StrictMode effect replay, preventing duplicate additions. The browser test reproduced two requests before the correction and verifies one afterward.
- Notification reads update both the list metadata and shared badge count. Failed marking does not navigate away.
- Invoice details use an abortable data boundary and retry. PDFs continue to download as authenticated Axios blobs and use the response filename.
- Return and payment-notice order selectors read all API pages, expose loading/errors, and use actual server IDs.
- Return attachment validation covers five files per item, ten overall, 5 MB per file, 25 MB overall, and JPEG/PNG/WebP. Invalid batches are rejected before submission; accepted photos can be removed. Server validation errors map back to item fields.
- Quotation editing exposes fetch errors and retry; a failed submit after creating a draft retains the draft ID for retry. Target-price field errors are visible.
- Application entry reports failures and document statuses reflect the server. Cancelled order history is sorted chronologically.
- Removed the unused mock data module. Remaining timers implement debounce, toast expiry, and blob URL cleanup.

## Verification

Run from `bags-platform/frontend`:

```powershell
node --test tests/*.test.cjs
pnpm --filter @alfarid/api-client --filter @alfarid/ui --filter @alfarid/storefront typecheck
pnpm test:wholesale
pnpm test:wholesale:e2e
```

The full frontend unit/contract suite passed: **118 tests**. Type checks passed for all three affected packages. A temporary relaxation of the image count limit was caught by the regression test and then restored.

The browser suite runs against `http://localhost:3001` by default (`STOREFRONT_URL` overrides it), with a running storefront dev server and Playwright/Edge. It covers **nine scenarios**: account retry, cart retry, notification counts, authenticated invoice download, single reorder mutation, stale/changed checkout prices and success reload, eleven-image rejection, account suspension redirect, and approval entry. It checks for browser runtime errors and saves screenshots under `test-results/wholesale-portal`.

The existing Laravel wholesale suite also passed against the testing database: **70 tests, 419 assertions**, via `php artisan test --compact --filter=Wholesale` from `bags-platform/backend`.

Browser API responses are controlled fixtures; Laravel feature tests exercise the real backend independently. This is not a claim that every manual QA flow in `task.txt` was exercised against a live admin/storefront pair. No production deployment or production database seeding was performed.
