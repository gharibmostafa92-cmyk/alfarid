# Human QA Session Log

- **2026-11-18**: Initialized `.human-tests/` tracking, built `ROADMAP.md` (9 ordered modules), and started `01-auth`.
- **2026-11-19**: User confirmed all `01-auth` cases (`HT-AUTH-01`–`HT-AUTH-08`) passed. Marked `01-auth` completed and advanced to `02-account`.
- **2026-11-19**: Verified `HT-ACC-01`–`HT-ACC-06` with local Playwright checks and 38 Laravel feature tests. All six cases passed. Marked `02-account` completed and advanced the roadmap to `03-catalog-storefront`.

- **2026-11-19**: Ran catalog feature suites for `HT-CAT-01`–`HT-CAT-03`. Recorded home verification as blocked by incomplete coverage and a cache assertion failure, brands as failed (HTTP 410), and product filtering as pending further checks. The module remains in progress.

- **2026-11-19**: Re-enabled the read-only storefront brands endpoint, added category response fields, and verified `HT-CAT-01`–`HT-CAT-03` with 53 passing catalog-related feature tests (298 assertions). A broader run also exposed unrelated customer/account, dealer-document and payment failures; the module remains in progress for cases 04–06.

- **2026-11-20**: User confirmed `HT-CAT-04`–`HT-CAT-06` passed. Marked `03-catalog-storefront` completed and advanced the roadmap to `04-cart-checkout-payments`.

- **2026-11-20**: Verified `HT-CHK-01`–`HT-CHK-07` with 114 Laravel tests on PostgreSQL, 37 focused frontend tests, and Playwright on desktop and mobile with API fixtures. Restored the `/cart` route in the storefront app, fixed repeated media queries, and added rate-limited warnings for oversized payment and shipping webhooks. The broader backend and frontend runs encountered additional failures outside the passing Module 04 checks.
