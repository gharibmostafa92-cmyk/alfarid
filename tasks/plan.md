# Implementation Plan: task7.txt hardening roadmap

Source: `_archive_unused/tasks_execution/task7.txt` (findings F01–F21).
No `k6.txt` or `task72.txt` exists anywhere — no scope expansion.

## Audit verdict (evidence-backed)

| Item | Status | Evidence |
|---|---|---|
| F01 coupon IP limit trusts cart token | OPEN — implement | `CouponService:69-82,96-108` keys limiter on `X-Cart-Session`/`cart_token` |
| F02 price snapshot ignores Product/PriceList | PARTIAL — implement | `CatalogRetailPriceObserver:105-110` returns early for Product; PriceList falls through to global refresh |
| F03 logout path/header token race | PARTIAL — implement | unified `/api/v1/auth/logout` deletes current token; `SophieHeader:179-186` applies stale `meData` without token equality guard |
| F04 middleware cache/static paths | OPEN — implement | `middleware.ts:45` matcher lacks `store-media|vendor|healthz`; Cache-Control already strong |
| F05 store-media rewrite drops path | OPEN — implement | `next.config.mjs:10` uses `${u.origin}/:path*` instead of preserving `u.pathname` |
| F06 product code/SKU whitespace | PARTIAL — implement | create retries exist; no `trim()` before empty checks |
| F07 afterCommit/phone enumeration timing | PARTIAL — implement | `CustomerAuthService:257` afterCommit outside txn; `AuthController:145-150` skips dispatch for existing phones |
| F08 phone validation normalisation | OPEN — implement | `CheckoutRequest` lacks trait; `ForgotPasswordRequest`/`PhoneVerificationRequest` Egyptian-only regex |
| F09 Web Locks fallback | PARTIAL — implement | `cart-store.ts` rethrows `SecurityError`; mega dropdown guard needs verify |
| F10 facets/on_sale pricing context | VERIFIED — document | public-retail filter + cache key documented; `categories` locale benign |
| F11 staging media cleanup | PARTIAL — implement | hourly staging prune exists; no owner-wide stale cleanup at upload start |
| F12 chunked body limit | OPEN — implement | `cloud-run-api.ts` checks only `content-length` |
| F13 persist reorder expected ids | PARTIAL — implement | `persist.ts` uses pre-upload `originalIds` |
| F14 checkout restore race | OPEN — implement | `useCheckout.ts` effect deps omit `items` |
| F15 POS undo duplicate | OPEN — implement | `pos-cart.core.ts:234` appends without dedupe |
| F16 categories/design/popup effects | OPEN — implement | side effects in wishlist updater; design timers uncleaned; popup trap misses dialog focus |
| F17 numeric route constraints | OPEN — implement | `store.php:65-66` item routes and `:89` cancel lack constraints |
| F18 similar/track N+1 | OPEN — implement | `similar()` 40 candidates + second query; `track()` eager loads before phone check |
| F19 safe href helper | OPEN — implement | no `safeStoreHref`; footer/promo/products use raw hrefs |
| F20 account labels | VERIFIED | Arabic labels and logout text already correct |
| F21 next.config import + POS duplication | PARTIAL — implement | import at EOF; POS core duplication recorded |

## Task list (vertical slices, sequential — shared files)

### Phase A — backend correctness
- [ ] T1: F01 coupon IP/customer limiter + failing test
- [ ] T2: F02 Product/PriceList snapshot refresh + observer test
- [ ] T3: F06 trim product code/SKU + test
- [ ] T4: F07 afterCommit inside txn + always-dispatch phone verification + tests
- [ ] T5: F08 normalise checkout/forgot/phone requests + tests
- [ ] T6: F17 numeric route constraints + tests
- [ ] T7: F18 similar single query + track load-after-match + tests

### Phase B — frontend correctness
- [ ] T8: F03 token equality guard + logout verification
- [ ] T9: F04 middleware matcher exclusions
- [ ] T10: F05/F21 media rewrite path + import placement
- [ ] T11: F12 streaming byte limit
- [ ] T12: F13 persist expected ids
- [ ] T13: F14 checkout effect deps
- [ ] T14: F09 cart Web Locks fallback
- [ ] T15: F15 POS undo dedupe
- [ ] T16: F16 categories/design/popup effect cleanup
- [ ] T17: F19 safeStoreHref helper + call sites

### Phase C — media/docs
- [ ] T18: F11 owner-wide stale staging cleanup + test
- [ ] T19: F10/F20/F21 residual documentation

### Phase D — tracking
- [ ] T20: update `task7.txt` markers + deployment doc (Deployed vs Pending)
- [ ] T21: repomix configs/outputs sync once
- [ ] T22: `php artisan test` + `pnpm -r typecheck` + `pnpm -r build` + final report

## Risks
| Risk | Impact | Mitigation |
|---|---|---|
| Coupon limiter change tightens guest NAT limits | Med | keep IP cap configurable, add customer-scoped second key |
| Phone validation widening allows junk | Med | normalise then validate canonical E.164/EG format |
| Observer Product mapping triggers broad refresh | Med | map to exact product id; batch dedupe |
| Frontend rewrite path change breaks local media | Med | preserve pathname only when present; fallback unchanged |
| POS shared-core refactor too broad | Med | defer package unification; document duplication as accepted |
