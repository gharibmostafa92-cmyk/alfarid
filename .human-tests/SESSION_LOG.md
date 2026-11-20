# Human QA Session Log

- **2026-11-18**: Initialized `.human-tests/` tracking, built `ROADMAP.md` (9 ordered modules), and started `01-auth`.
- **2026-11-19**: User confirmed all `01-auth` cases (`HT-AUTH-01`–`HT-AUTH-08`) passed. Marked `01-auth` completed and advanced to `02-account`.
- **2026-11-19**: Verified `HT-ACC-01`–`HT-ACC-06` with local Playwright checks and 38 Laravel feature tests. All six cases passed. Marked `02-account` completed and advanced the roadmap to `03-catalog-storefront`.

- **2026-11-19**: Ran catalog feature suites for `HT-CAT-01`–`HT-CAT-03`. Recorded home verification as blocked by incomplete coverage and a cache assertion failure, brands as failed (HTTP 410), and product filtering as pending further checks. The module remains in progress.

- **2026-11-19**: Re-enabled the read-only storefront brands endpoint, added category response fields, and verified `HT-CAT-01`–`HT-CAT-03` with 53 passing catalog-related feature tests (298 assertions). A broader run also exposed unrelated customer/account, dealer-document and payment failures; the module remains in progress for cases 04–06.

- **2026-11-20**: User confirmed `HT-CAT-04`–`HT-CAT-06` passed. Marked `03-catalog-storefront` completed and advanced the roadmap to `04-cart-checkout-payments`.

- **2026-11-20**: Verified `HT-CHK-01`–`HT-CHK-07` with 114 Laravel tests on PostgreSQL, 37 focused frontend tests, and Playwright on desktop and mobile with API fixtures. Restored the `/cart` route in the storefront app, fixed repeated media queries, and added rate-limited warnings for oversized payment and shipping webhooks. The broader backend and frontend runs encountered additional failures outside the passing Module 04 checks.

- **2026-11-20**: User confirmed `HT-POS-01`–`HT-POS-04` passed in `05-pos`. Remaining cases: `HT-POS-05`–`HT-POS-07`.
- **2026-11-20**: Verified `HT-POS-05`–`HT-POS-07` via 97 passing Laravel POS/stocktake feature tests (1,085 assertions) and `@alfarid/pos` + `@alfarid/pos-core` typecheck and production Vite build. Marked `05-pos` completed and advanced roadmap to `06-wholesale-supplier`.
- **2026-11-20**: Verified all six `06-wholesale-supplier` cases using PostgreSQL feature tests and local browser runs with API fixtures. Fixed replacement document type serialization, added the optional supplier ASN invoice input, and wired current terms consent into wholesale checkout. Marked the module as passed by automated verification.
