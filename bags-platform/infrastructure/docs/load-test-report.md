# Load test report — measured 2026-10-28 (single host) + pending multi-node matrix

Environment: see `baseline.md` (single php -S worker, `alfarid_baseline`
with 2000 published products, k6 v2.2.0). Raw evidence (gitignored, kept
next to the scripts): `infrastructure/k6/results/` —
`baseline-single-20261028.log` (Run A), `baseline-single-20vu.log` (Run B),
`baseline-single-1vu-p99.log` + `baseline-single-1vu.json` (Run C),
`checkout-race6.log` + `checkout-race.json` (test #9),
`baseline-resources.csv`. No Grafana here (needs compose) — evidence is k6
stdout summaries + JSON exports, not screenshots.

| # | Test | Expected | Measured |
|---|---|---|---|
| 1 | 1 node, no LB | baseline | **Done.** Run C: p50 ≈ 303ms, p95 ≈ 403ms, p99 ≈ 423ms, 0% errors, ~1.55 mixed-browse/s per worker. Run A: dev accept-queue collapses past ~350 VUs (99.48% TCP-refused, host CPU avg 28.5%) |
| 2 | 3 nodes, same load | ~2.5–3× RPS | **Not measurable here** — Docker daemon never started (see below). Run: `docker compose up -d` then `k6 run --env BASE_URL=http://<VIP> store-load.js` vs Run A |
| 3 | Ramp to p95 > 1s | system ceiling | **Partially measured (dev SAPI):** p95 passes 1s between 1 VU (403ms) and 20 VUs (6.41s). FPM ceiling still open — rerun test on compose |
| 4 | `docker kill app2` mid-load | < 0.5% errors | **Not measurable here** (no daemon). Run: start k6, `docker kill alfarid-lb-app2-1`, count 5xx + X-Served-By spread |
| 5 | `docker stop redis1` | failover < 10s | **Not measurable here** (no daemon, no local Redis). Run: stop redis1 mid-load, watch haproxy-data backend switch master |
| 6 | `docker stop haproxy1` | VIP < 3s | **Not measurable here** (Keepalived needs L2/Linux). Run: stop haproxy1, watch VIP move + zero failed checks |
| 7 | Rolling deploy mid-load | 0 errors | **Not measurable here.** Run: `bash infrastructure/scripts/rolling-deploy.sh` during k6, assert 0 failed checks |
| 8 | Spike 0→1000 users / 10s | queued, no crash | **Dev-server analogue observed in Run A:** 0→500 VUs refused (no queue in php -S). HAProxy `timeout queue 20s` + `maxconn 32` is designed for exactly this — verify on compose |
| 9 | 50 parallel checkouts, 1 variant, 1 unit | no oversell, no dupes | **Done — PASS.** 50/50 cart adds 2xx; checkouts: **1× 201** (order ORD-001107), **49× clean 409/422**, **0× 500**. DB: exactly 1 order item row for the race (qty sum = 1), reserved movement total = 1.0, **0 duplicate idempotency pairs**. Latencies inflated by single-worker serialization (med ~8–14s) — correctness unaffected |

## Test #9 protocol (reproducible)

Variant 4024 (published product), stock forced to exactly 1 unit;
`CITY_ID=1`. Real guest flow per iteration (this was fixed twice during
measurement — see notes): UUID `X-Cart-Session` → `POST cart/items` →
adopt server-issued `data.cart_token` → `POST checkout/` with
`Idempotency-Key`. A queue worker drained `RefreshCatalogRetailPrices`
during the run (production-like: workers always on). Command:

```bash
k6 run --env BASE_URL=http://127.0.0.1:8000 --env VARIANT_ID=4024 \
  --env CITY_ID=1 checkout-race.js
```

## Findings (need owner decision — no app code changed)

- **F1 — Unique-job dispatch inside a Postgres transaction + `database`
  cache store = hard 500 on lock contention.** `DatabaseLock::acquire()`
  (vendor) does INSERT-then-UPDATE; on Postgres the duplicate-key INSERT
  aborts the whole checkout transaction, so the fallback UPDATE also fails
  (25P02 → HTTP 500) instead of skipping the dispatch. Trigger: two
  checkouts touching the same snapshot batch key within `uniqueFor`
  (300s) while a lock row exists. Production per plan uses
  `CACHE_STORE=redis` (atomic Lua locks — immune); any env with
  `CACHE_STORE=database` + queue pressure is exposed. Suggested follow-up
  (app change, needs approval): dispatch the snapshot refresh
  `afterCommit`, or catch-and-skip unique-dispatch failures inside the
  checkout transaction.
- **F2 — Guest carts only work with UUID sessions + token adoption.**
  `CartService::resolveCart` nulls non-UUID tokens (fresh empty cart per
  request), and `Cart::creating` assigns a fresh random token (the
  `present()` response carries it). k6/clients must send a UUID and adopt
  `data.cart_token` from the add-to-cart response — `checkout-race.js`
  documents both.

## k6 script notes (test tooling only, no business logic touched)

- `store-load.js`: stages now env-overridable (`STAGE1_VUS/DUR` …),
  **defaults unchanged** (= the plan's canonical 2m@200/5m@500/2m matrix).
  `fetch-slugs.cjs`/`.sh` use `per_page=48` (API max, was 100 → 422).
- `checkout-race.js`: rewritten to the real API (cart session → add item →
  checkout with `city_id/shipping_address_line/payment_method/cod` +
  `recipient_name/phone` + `Idempotency-Key`); the first version posted
  `items[]` in the checkout body, which this API ignores (items come from
  the cart) — every run before the rewrite measured an empty-cart path.

## Docker daemon status (tests #2–8 blocked)

Docker Desktop 29.6.1 client present, k6 present; the Linux-engine daemon
never became reachable (`docker info` = client only) despite launching
Docker Desktop and ~40 min of retries. Multi-node, failover, VIP and
rolling-deploy tests must run on a Linux host (16 GB RAM per plan) with a
working daemon. No numbers above are extrapolated — unmeasured cells stay
explicit.
