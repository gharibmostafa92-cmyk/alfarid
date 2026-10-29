# Runbook

## App node down

1. HAProxy health check (`/health/ready`) marks it DOWN in ~9s (3 × 3s);
   traffic shifts automatically.
2. Confirm: HAProxy stats `:8404/stats`, Grafana LB dashboard queue/5xx.
3. Fix or `docker compose up -d --force-recreate appN`; it rejoins via
   slowstart. Verify `/health/ready` 200 from inside the net.

## Redis master down

Sentinel promotes the replica (< 10s); haproxy-data TCP check follows the
new master automatically. If writes fail longer: `redis-cli -p 26379
sentinel masters`, then `sentinel failover mymaster`.

## Postgres slow

Grafana data dashboard: locks, deadlocks, slow queries; `SHOW POOLS` on
PgBouncer for pool waits. Kill the blocker (`pg_terminate_backend`), scale
`default_pool_size` only after measuring (40 default).

## Deploy / rollback

- Deploy: `bash infrastructure/scripts/rolling-deploy.sh` (backward-compatible
  migrations only). During mixed versions old code must ignore new nullable
  columns; drops happen one deploy later.
- Rollback: rebuild the previous image tag and re-run the script; migrations
  are never rolled back automatically — write a forward fix instead.

## Backup restore

Nightly `pg-backup.sh` writes `pg_dump -Fc` to `/backups` + R2
(`postgres-backups/`), keeps 14. Restore test (do once, record date):

```bash
pg_restore -h postgres -U postgres -d alfarid_restore /backups/alfarid-<stamp>.dump
```

Log the restore date + duration in `load-test-report.md` appendix.
