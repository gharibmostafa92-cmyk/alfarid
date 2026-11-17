# Task4 — Infrastructure, Security, Concurrency & Maintenance Hardening Roadmap

The infrastructure checklist below is the 2026-11-15 execution. `_archive_unused/tasks_execution/task4.txt` now holds a separate storefront defect list (F01–F20), marked complete locally on 2026-11-17. That later list is not deployed. Its pending steps are in `bags-platform/docs/deployment-status.md`.

Date of the infrastructure execution: 2026-11-15
Infrastructure status: implemented and verified locally. Live deployment of those items remains unverified.

---

# Deployed vs. Pending Deployment

## A. Already Deployed on Server

> [!IMPORTANT]
> Live server deployment status is **unverified / not deployed**. No live server access was used and no production databases or services were migrated or restarted. All changes below are implemented and verified locally only.

- **Current Live Server Baseline:** Pre-execution state (refer to `docs/.last-deployed`; live server state unverified).
- **Applied Database Migrations:** Migration `2026_11_15_000004_optimize_indexes_and_jobs_autovacuum.php` and updates to `2026_11_12_000013`, `000021`, `000061`, `000083` are **not** yet applied on production.
- **Active Environment Configuration:** Production `.env` still requires separation of `DB_MIGRATION_USERNAME` / `DB_MIGRATION_PASSWORD` from `DB_USERNAME` / `DB_PASSWORD`, plus `KEEPALIVED_AUTH_PASS` (if `vip` profile is used) and `SUPPLIER_TOKEN_MINUTES=720`.
- **Queue and Scheduler Configuration:** Live `whatsapp-worker` (if enabled) and `scheduler` still run pre-patch code.

---

## B. Ready / Pending Server Deployment

### 1. Critical Findings (4 Items)

- **`[CRITICAL-01]` Separate DB Migration Owner & Runtime Roles:**
  - Separated `DB_MIGRATION_USERNAME` / `DB_MIGRATION_PASSWORD` (used by `postgres`, `migrate`, `backup`) from non-owner `DB_USERNAME` / `DB_PASSWORD` (used by `pgbouncer`, `app1..3`, workers).
  - Added `bags-platform/infrastructure/docker/postgres-init-runtime.sh` mounted to `/docker-entrypoint-initdb.d/10-runtime-role.sh` to provision the least-privilege runtime role and default DML privileges.
  - Updated `bags-platform/infrastructure/docker-compose.yml`, `bags-platform/infrastructure/.env.example`, and `bags-platform/infrastructure/scripts/setup.mjs`.

- **`[CRITICAL-02]` Cloud Run `pilot.ps1` Production Guard Alignment:**
  - Added validated `$MailMailer` (default `smtp`, rejecting `log`/`failover`/`array`), `$MailHost`, `$MailPort`, and `$TrustedProxies` (rejecting `*` and `none`) parameters to `bags-platform/infrastructure/cloud-run/pilot.ps1`.
  - Enforced `PROXY_CLIENT_IP_SECRET` secret pinning on both `DeployApi` and `DeployAi`.

- **`[CRITICAL-03]` `X-Forwarded-For` Spoofing Prevention:**
  - Updated `fe_internal` in `bags-platform/infrastructure/haproxy/haproxy.cfg` to collapse `X-Forwarded-For` to a single hop (`%[req.hdr_ip(X-Forwarded-For,-1)]` or `%[src]`).
  - Removed storefront container IPs (`172.31.0.20,172.31.0.21`) from `TRUSTED_PROXIES` in `bags-platform/infrastructure/docker-compose.yml` and `bags-platform/backend/.env.production.example`.

- **`[CRITICAL-04]` Keepalived VRRP Secret Injection:**
  - Replaced hardcoded `ALFdemo1` in `bags-platform/infrastructure/keepalived/keepalived-master.conf` and `keepalived-backup.conf` with `__KEEPALIVED_AUTH_PASS__`.
  - Updated `bags-platform/infrastructure/docker-compose.vip.yml` to inject `KEEPALIVED_AUTH_PASS` (truncated to 8 bytes per VRRP spec) at container startup, and updated `setup.mjs` / `.env.example` to generate it.

### 2. High Priority Findings (5 Items)

- **`[HIGH-01]` POS Stocktake & Transfer Route-Level Permissions:**
  - Added explicit `permission:` middleware on `stocktakes/current`, `stocktakes/{stocktake}/scan`, `transfers/incoming`, `transfer-sources`, `transfer-requests`, and `transfers/{transfer}/receive` in `bags-platform/backend/routes/api/pos.php`.

- **`[HIGH-02]` AI SSE Streaming Timeout & Lock Budget Alignment:**
  - Raised AI conversation turn timeout in `bags-platform/frontend/apps/storefront/src/lib/core/cloud-run-api.ts` from `60_000` to `180_000` ms.
  - Raised `alfarid-ai` Cloud Run timeout in `pilot.ps1` from `60s` to `180s`.
  - Routed `/api/v1/admin/ai/conversations` to `be_api_slow` (180s) in `bags-platform/infrastructure/haproxy/haproxy.cfg`.
  - Capped `Retry-After` in `bags-platform/backend/app/Http/Middleware/PreventConcurrentAiStream.php` to `<= 10` seconds instead of `600`.

- **`[HIGH-03]` Redis Replication Keepalive & Fast Failover Detection:**
  - Added `tcp-keepalive 60` and `repl-ping-replica-period 5` to `bags-platform/infrastructure/redis/start.sh`.
  - Tightened `haproxy-data.cfg` health checks from `inter 1s` to `inter 500ms`.

- **`[HIGH-04]` Constraint Validation `lock_timeout` & `statement_timeout` Safety:**
  - Removed short `SET LOCAL statement_timeout = '10s'` from `2026_11_12_000083_pos_tender_includes_exchange_credit.php`.
  - Added `pg_constraint` existence/unvalidated checks and `SET LOCAL lock_timeout = '5s'` to `2026_11_12_000021_verify_task2_check_guards.php`, `000061`, and `000013`.

- **`[HIGH-05]` Remove Internal SPOFs (`wa-gateway` & `storefront`):**
  - Assigned static IP `172.31.0.25` to `wa-gateway` in `docker-compose.yml`, added it to `bags-platform/infrastructure/haproxy/internal-clients.lst`, and routed `WA_LARAVEL_INBOUND_URL` through `${API_INTERNAL_URL:-http://haproxy1:8081}`.
  - Parameterized `API_INTERNAL_URL` on `storefront1`/`storefront2` so `vip` deployments can point to `http://172.31.0.100:8081`.

### 3. Medium, Low & Informational Findings (10 Items)

- **`[MEDIUM-01]` PgBouncer Pool Sizing:** Raised `default_pool_size = 80`, `reserve_pool_size = 20`, `max_db_connections = 100` in `bags-platform/infrastructure/pgbouncer/pgbouncer.ini`.
- **`[MEDIUM-02]` `SuggestTransferRedistribution` Hardening:** Added `?? null` guard, active `branches`/`locations` filtering, deterministic ordering, and 12-hour `Cache::add` deduplication.
- **`[MEDIUM-03]` Index Optimization:** Added `2026_11_15_000004_optimize_indexes_and_jobs_autovacuum.php` to drop duplicate `finance_reconcile_runs_created_at_idx` and rebuild `orders_pending_expiry_idx` with `(created_at, id) INCLUDE (payment_method) WHERE status = 'pending'`.
- **`[MEDIUM-04]` `rolling-deploy.sh` Hardening:** Added `whatsapp-worker` restart when running and optional `ENABLE_MONITORING` / `ENABLE_VIP` compose file flags.
- **`[MEDIUM-05]` Maintenance Commands:** Added 3000s time-budget guards in `PruneOrphanMediaFiles.php` and `MigrateUploadsToR2.php`, and SQLSTATE `22P02` fallback in `PrunePrivateAttachments.php`.
- **`[MEDIUM-06]` `EnsureStaffAuthenticated` Redis Fallback:** Wrapped `Cache::remember('branch-active:...')` in `try/catch (\Throwable)` with direct DB fallback.
- **`[MEDIUM-07]` Arabic Error Messages:** Verified `ManufacturingBusiness.php`, `EnsureUnifiedAccountActive.php`, and `2026_11_15_000003_link_supplier_payments_to_treasury.php` all have intact UTF-8 Arabic messages.
- **`[LOW-01]` Supplier Token TTL & `pos-pages`:** Lowered `supplier_token_minutes` default to `720` in `config/auth.php` and `.env.production.example`; added `bags-platform/infrastructure/cloud-run/pos-pages/build.mjs` and replaced staging `.pages.dev` links.
- **`[LOW-02]` `scripts/repomix-started-sync.ps1`:** Fixed `[bool]$Pack`, PS 5.1 relative path resolution, `**/` glob regex, `[regex]::Escape($Domain)`, and `git status` rename (`->`) parsing.
- **`[INFO-01]` `jobs` Autovacuum Tuning:** Tuned `jobs` table (`autovacuum_vacuum_scale_factor = 0.01`, `autovacuum_vacuum_cost_limit = 1000`) in `2026_11_15_000004_optimize_indexes_and_jobs_autovacuum.php`.

---

## Required Production Deployment Steps

1. **Provision Separate DB Roles (if upgrading an existing PostgreSQL data volume):**
   - Ensure `DB_MIGRATION_USERNAME` / `DB_MIGRATION_PASSWORD` own the schema and `DB_USERNAME` / `DB_PASSWORD` is a non-owner role with DML + sequence `USAGE, SELECT` privileges.
2. **Update `.env` / Secrets:**
   - Set `DB_MIGRATION_USERNAME`, `DB_MIGRATION_PASSWORD`, `SUPPLIER_TOKEN_MINUTES=720`, and `KEEPALIVED_AUTH_PASS` (8 chars, if `vip` profile is enabled).
   - Verify `TRUSTED_PROXIES=172.31.0.10,172.31.0.11` (and `API_INTERNAL_URL=http://172.31.0.100:8081` if `vip` is active).
3. **Run Migrations & Rolling Deploy:**
   ```bash
   cd bags-platform/infrastructure
   ./scripts/rolling-deploy.sh
   ```
4. **Regenerate `pos-pages` (if Cloudflare Pages redirect is deployed):**
   ```bash
   POS_TARGET_URL=https://admin.alfarid.example/pos node bags-platform/infrastructure/cloud-run/pos-pages/build.mjs
   ```
