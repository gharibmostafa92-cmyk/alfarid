# Architecture — bags-platform load balancing

Internet → VIP (Keepalived) → HAProxy 1/2 (TLS, routing, edge rate limit,
health checks) → app1/app2/app3 (Nginx+PHP-FPM, port 8080) → PgBouncer →
Postgres (+ optional replica); app → haproxy-data → Redis master/replica +
3 Sentinel. Workers x2 + media-worker + scheduler x1. Storefront x2
(Next.js), backoffice + POS (static Nginx).

## Why each decision

- `leastconn` for `be_api`: catalog vs checkout requests have very different
  durations; least-connections sends work to the idlest node.
- `maxconn 32` per node = `pm.max_children` (measure with
  `ps -ylC php-fpm --sort:rss`, ~60-90 MB each; 3 GB / 80 MB ≈ 36 → 32).
  HAProxy queues the overflow (`timeout queue 20s`) instead of drowning PHP-FPM.
- `be_api_slow` (reports, wholesale export, price lists): longer server
  timeout (180s) but only 8 conns per node, so exports cannot starve checkout.
- `disable-l7-retry` except GET/HEAD: HAProxy never replays a POST, so an
  order cannot duplicate — complements `OperationIdempotencyService` and the
  409-on-write deadlock handler.
- `slowstart 20s`: a node returning from deploy warms OPcache gradually.
- Edge limit (300 req / 10s per IP, 100 concurrent): above Laravel limits;
  it stops floods before PHP. POS branches behind NAT go in `whitelist.lst`.
- `fe_internal :8081` for Next.js SSR: same backends but keeps
  X-Forwarded-For (fe_public strips spoofed values, then sets the real one).
- Queue stays `database` with `after_commit=true`: Postgres spreads jobs
  across workers without duplication and jobs survive deploys.
- PgBouncer `transaction` mode: 3 nodes × 32 workers + workers/scheduler
  collapse to ~40 real connections. Safe because every advisory lock in the
  codebase is `pg_advisory_xact_lock*` (transaction-scoped); no
  `pg_advisory_lock` (session-scoped) and no bare `SET` were found.
- Redis via haproxy-data + Sentinel: the configured client does not discover Sentinel, so the
  TCP frontend routes to the current master (info replication role:master
  check). The target is recovery in < 10s; see measured acceptance results.
- Scheduler: exactly one `scheduler` container; every `Schedule` entry also
  has `->onOneServer()` backed by the shared Redis cache.
- State: `CACHE_STORE/LIMITER=redis`, `SESSION_DRIVER=redis`,
  `APP_MAINTENANCE_DRIVER=cache` + `APP_MAINTENANCE_STORE=redis`,
  `LOG_STACK=stderr` (JSON) → Loki. Files: default disk is `r2`;
  `BANNERS_DISK` must be `r2`/`r2_public` in production; run
  `php artisan uploads:migrate-to-r2`, then point `/store-media` at
  `R2_PUBLIC_URL`.

## Runtime compatibility and limits

PHP 8.3 requires `DB_DISABLE_PREPARES=true` for transaction pooling. Named
statement cleanup sends SQL DEALLOCATE and aborts transactions; the opt-in PDO
driver setting uses unnamed parameterized queries. A transaction probe and the
PostgreSQL test suite guard this path. Migrations bypass PgBouncer.

Compose reserves 172.31.0.128/25 for dynamic addresses so exporters/workers cannot
acquire a trusted proxy's static IP. Redis uses `noeviction`: evicting shared locks
or rate-limit counters under memory pressure would break correctness. Redis and
Sentinel role/config rewrites persist independently per node.

This is service redundancy on one Docker host, with one PostgreSQL primary and
one data proxy. Real host failure protection requires distributing services over
independent machines.
