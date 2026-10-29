# Alfarid multi-node deployment

Run commands from `bags-platform/infrastructure/`. Docker Desktop demonstrates the
bridge VIP; public VIP routing and host failure require independent Linux hosts
on the same L2 network. One Compose host cannot survive failure of that machine.
PostgreSQL and haproxy-data are single instances.

## First boot

```bash
node scripts/setup.mjs
docker compose build app1 postgres haproxy1
node scripts/setup.mjs --certs
bash scripts/compose.sh --profile frontend --profile vip up -d --build
docker compose run --rm --no-deps -e DB_HOST=postgres -e DB_PORT=5432 app1 php artisan migrate --force
```

`setup.mjs` preserves existing `.env`, generates random local credentials for a
new one, and keeps secrets out of images. Its certificate is for a local demo.
Configure real domains, R2, SMTP, CORS and host ports in `.env` for production.
Only certificate/key PEM bundles belong in `haproxy/certs/`: loose `.crt` files
are also loaded by HAProxy and fail without a matching key. Use URL-safe database
and Redis passwords.

The generated local example uses 8 PHP workers per node for an 8 GB Docker VM.
`APP_MAXCONN` controls both PHP-FPM workers and HAProxy's regular connection limit;
`APP_SLOW_MAXCONN` limits report/export traffic. For larger hosts, measure process
memory before increasing them. The base Compose fallback remains 32 workers.

Local ports: HTTP 8088, HTTPS 8443; backup edge 8089/8444. Grafana:
http://127.0.0.1:3008 (`admin`, password in `.env`); Prometheus:
http://127.0.0.1:9098; edge stats http://127.0.0.1:8404/stats (`admin`, stats
password in `.env`). Stats/monitoring bind loopback. Database/cache ports are
internal. Add the four `*.alfarid.example` hostnames to local DNS/hosts for full
browser tests; `.example` domains are placeholders.

```bash
node scripts/smoke.mjs
bash scripts/compose.sh exec app1 curl -fsS http://localhost:8080/health/ready
```

The smoke script accepts the self-signed local demo certificate. Public
`/health*` and `/up` return 404. The internal API frontend accepts only SSR nodes;
public forwarding headers are overwritten at the edge.

## Test and seed (isolated staging only)

```bash
bash scripts/test-backend.sh
```

This builds a separate development-dependency image and explicitly sets
`DB_DATABASE=alfarid_testing` before PHP starts. The suite resets this test DB.
Never point it at a live database. PHP 8.3 uses `DB_DISABLE_PREPARES=true` through
PgBouncer: bound parameters remain in the PostgreSQL protocol without named
prepared statements. Direct connections retain the existing default behavior.

For a disposable demo dataset (creates demo accounts/catalog):

```bash
docker compose -f docker-compose.yml -f docker-compose.tests.yml run --rm --no-deps --entrypoint php -e APP_ENV=local -e DB_DATABASE=alfarid tests artisan db:seed --force
docker compose -f docker-compose.yml -f docker-compose.tests.yml run --rm --no-deps --entrypoint php -e APP_ENV=local -e DB_DATABASE=alfarid tests artisan db:seed --class=LoadTestSeeder --force
docker compose exec app1 php artisan store:refresh-retail-prices
```

Refresh `k6/slugs.json` with `k6/fetch-slugs.cjs` against that dataset. Explicitly
opt into the staging override, which trusts the fixed k6 IP only for tests:

```bash
LOAD_TEST=1 bash scripts/compose.sh --profile frontend --profile vip up -d
node scripts/run-load.mjs three-nodes http://haproxy1:8081 200 60s
node scripts/run-load.mjs single-node http://app1:8080 200 60s
```

Use identical load/duration for comparisons. `run-load.mjs` adds 10-second ramps,
exports JSON/logs, and sends live p95/p99 to Prometheus. The canonical nine-minute
matrix remains the default in `k6/store-load.js`. Failed thresholds exit nonzero
and retain evidence. p95 comes from k6 during tests; HAProxy average latency is
not mislabeled as a percentile.

```bash
node scripts/fault-check.mjs app
node scripts/fault-check.mjs redis
node scripts/fault-check.mjs vip
LOAD_TEST=1 SKIP_BUILD=1 bash scripts/rolling-deploy.sh
```

Fault scripts kill only this project's named demo containers and restore them in
`finally`. Run one at a time after the stack is healthy. Set
`REDIS_PRIMARY=redis2` for a repeated Redis test if that is now master.
Keepalived's bridge VIP is 172.31.0.100. Host port 8443 always belongs to edge 1;
the VIP probe runs inside storefront1 to test actual takeover.

## Operations

See [runbook](docs/runbook.md), [architecture](docs/architecture.md),
[scaling](docs/scaling-guide.md) and [measured results](docs/load-test-report.md).
Optional micro-cache, Octane and read replicas require separate validation.
Alertmanager sends no external messages until an operator configures a real
email or Telegram destination.

Run `php artisan uploads:migrate-to-r2` against the intended existing media volume
with valid R2 credentials. Local tests do not establish that a real bucket media
migration or remote backup upload succeeded.

References: [PHP PDO PostgreSQL](https://www.php.net/manual/en/class.pdo-pgsql.php),
[PgBouncer compatibility](https://www.pgbouncer.org/faq.html),
[serversideup startup scripts](https://serversideup.net/open-source/docker-php/docs/customizing-the-image/adding-your-own-start-up-scripts),
[k6 Prometheus output](https://grafana.com/docs/k6/latest/results-output/real-time/prometheus-remote-write/).
