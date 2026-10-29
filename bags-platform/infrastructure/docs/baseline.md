# Baseline — single server, no LB (MEASURED 2026-10-28)

## Environment (what actually served traffic)

- Host: Windows dev machine, 15.9 GB RAM (5.8 GB free at start), k6 v2.2.0 local.
- App: `php artisan serve` on 127.0.0.1:8000 → PHP 8.3 built-in server,
  **single worker** (verified: exactly one `php -S` process; the
  `PHP_CLI_SERVER_WORKERS=8` env did not propagate through `artisan serve`).
- DB: local PostgreSQL 18.4, isolated database `alfarid_baseline`
  (the shared `alfarid` DB was wiped mid-session by an external actor, so all
  measurements use `alfarid_baseline`).
- Data: `migrate:fresh` + `db:seed` + `LoadTestSeeder` → **2010 products
  (2000 published)**, 4024 variants, 4000 stock rows, price snapshots refreshed.
- No Redis running: catalog endpoints use the local `database` cache store and
  are unaffected (`/health/ready` correctly reports `redis: fail` → 503;
  that fail-path was verified live).
- Test-only limiter relief via env (per plan §0):
  `STORE_CATALOG_IP_MINUTE=100000`, `STORE_PRICE_IP_MINUTE=100000`,
  `CART_GUEST_IP_MINUTE=100000`, `CHECKOUT_GUEST_IP_MINUTE=100000`
  (+ `_DAY` variants), `APP_EXPOSE_NODE_HEADER=true`.
- Load: `infrastructure/k6/store-load.js` (home → `products?sort=newest` →
  `products/{slug}`), 500 slugs from `fetch-slugs.cjs` (page size 48 = API max).

## Run A — canonical plan matrix (2 min ramp to 200 VUs, 5 min to 500, 2 min down)

Single-threaded dev server, 9 min. Full stdout:
`infrastructure/k6/results/baseline-single-20261028.log`.

| Metric | Value |
|---|---|
| Requests attempted | 200,026 (~351/s offered) |
| Failed | 199,001 (**99.48%**) — TCP connection refused once concurrency passed the dev server's accept queue (~350 VUs; 56k refused warnings in k6 log) |
| Passed checks | 1,025 (0.51%) — home/list/pdp all reachable when accepted |
| Successful-request duration | avg 43.2s, p95 59.4s (queue wait inside the single accept queue, not app time) |
| Iterations | 66,647 complete, 227 interrupted |

## Run B — 20 VUs, 2 min (same script, env stages)

`results/baseline-single-20vu.log`. 429 requests, **0% failed**, all checks pass.

| RPS | p50 | p90 | p95 |
|---|---|---|---|
| 3.1 req/s | ~4.4s | 6.24s | 6.41s |

Serialization-dominated (20 VUs × 1 worker).

## Run C — 1 VU, uncontended app+DB latency (~2 min, 87–195 reqs, 0% failed)

`results/baseline-single-1vu-p99.log` + `baseline-single-1vu.json`.

| RPS/worker | min | p50 | avg | p90 | p95 | p99 | max |
|---|---|---|---|---|---|---|---|
| ~1.55 mixed-browse/s (~3 req/s) | 278ms | 303ms | 309ms | 327ms | 403ms | 423ms | 456ms |

## Machine during Run A (66 × 10s samples, `results/baseline-resources.csv`)

| | avg | max |
|---|---|---|
| Total CPU | 28.5% | 71% |
| php RSS | 235 MB | 961 MB |
| postgres RSS | 197 MB | 296 MB |

The machine was never saturated — the bottleneck is the single-threaded dev
SAPI, not hardware. That is exactly what phases 2–3 replace
(PHP-FPM `pm.max_children=32` + HAProxy queuing).

## Buyer line (single dev-server worker, 2000 published products)

> ~3 mixed-browse req/s per PHP worker at p50 ≈ 300ms / p95 ≈ 400ms /
> p99 ≈ 423ms, 0% errors; the dev server's accept queue collapses past
> ~350 concurrent users while host CPU stays under 30%.

Reproduce: `php artisan serve --host=127.0.0.1 --port=8000`
(with `DB_DATABASE=alfarid_baseline` + the limiter env above), then
`k6 run --env BASE_URL=http://127.0.0.1:8000 store-load.js` from
`infrastructure/k6/` (defaults = Run A; bound it with
`STAGE1_VUS/STAGE1_DUR/STAGE2_VUS/STAGE2_DUR/STAGE3_VUS/STAGE3_DUR`).
