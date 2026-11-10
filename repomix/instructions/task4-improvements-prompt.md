# Task 4 Improvements, Security Vulnerabilities & Infrastructure Reliability Context

## Overview & Domain Scope
This Repomix bundle consolidates all core codebases, architecture configurations, database migrations, models, controllers, services, middleware, frontend auth/handoff logic, and comprehensive test suites addressing all vulnerabilities and reliability improvements cataloged in `task4.txt`.

The bundle encompasses three integrated pillars:
1. **Authentication, RBAC & Security Boundaries (Part 1):**
   - **H-1 / 000015:** Audit log pruning security definer vulnerability, minimum retention floor, and DB privileges (`alfarid_prune_staff_audit_logs`).
   - **H-2:** Elimination of repository hardcoded Sanctum tokens and `.env.local` segregation.
   - **M-1 & M-2:** Rate limiter locking mechanisms, Turnstile challenges, and fail-safe login workflows.
   - **M-3 & M-4:** Staff token lifecycle, request guard caching, idle timeout, and `last_used_at` contention.
   - **M-5, M-6, M-7:** Phone & email live-row unique constraints, customer email collision prevention, and N+1 prevention on user index.
   - **L-1 to L-11:** Default staff token abilities, atomic rate limiters, POS role classification, async alert notifications, CSRF handoff protection, and driver guards.

2. **Infrastructure, High Availability & Database Operations (Part 2):**
   - **C-1:** Database superuser privilege separation into Migration Owner, App DML, and pg_monitor roles across Docker, PgBouncer, and exporters.
   - **H-1 & H-2:** Redis split-brain prevention via Sentinel verification in `start.sh`, HAProxy `balance first`, and HA redundancy.
   - **H-3:** Explicit role timeout binding (`ALTER ROLE <runtime_role> IN DATABASE <db> SET ...`).
   - **H-4 & H-7:** Transaction-level advisory locks for sequences (`pg_advisory_xact_lock_shared` for `nextval`) and expand/contract deployment safety.
   - **H-5 & H-6:** Zero-downtime foreign keys and check constraint validation (`NOT VALID` -> separate validation transaction).
   - **H-8 & H-9:** Alertmanager routing, Prometheus alerting rules, and R2 WAL continuous archiving backup strategies.
   - **M-1 to M-17 & L-1 to L-11:** HAProxy health check uri normalization, PHP-FPM max children tuning, PgBouncer query wait timeouts, Redis retry fail-fast policies, SQLSTATE 23514 HTTP 409 exception handling, and rolling deployment traps.

3. **Private Attachments, Cloudflare R2 & Media Pipelines (Part 3):**
   - **H-1:** Storage cleanup owner DB binding and circuit breaker limits on mass pruning (`PrunePrivateAttachments`, `PruneOrphanMediaFiles`).
   - **H-2:** Elimination of synchronous `exists()` HTTP calls in download paths and optimized AWS SDK retry configurations.
   - **M-1 to M-7:** Protected `afterRollBack` error handling in `TransactionalMediaObserver`, mandatory batch upload idempotency, cache lock timeout tuning, derived files generation for video, storage error reporting, batch verification of referenced paths, and EXIF/GPS metadata sanitization.
   - **L-1 to L-8:** Robust array parsing in requests, PostgreSQL driver checks in migrations, video staging cleanup, and private URL lifetime defaults.

## Implementation Guidelines
- **Zero Omission:** All changes to business logic or database schemas must be verified against the included test suites (`Feature/Auth`, `Feature/Staff`, `Feature/System`, `Feature/Attachments`, `Feature/Admin/Catalog`).
- **Regression Safety:** Ensure that no existing contracts, foreign key restrictions, or idempotency guarantees are broken during remediation.
- **Production Idempotency:** Any database migrations or deployment script fixes must support non-blocking execution under live traffic.
