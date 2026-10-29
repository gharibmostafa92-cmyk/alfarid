# Scaling guide

## Add a 4th app node (3 steps)

1. `docker-compose.yml`: duplicate an `appN` service (same image, next free
   IP if you pin addresses, same env).
2. `haproxy/haproxy.cfg`: add `server app4 app4:8080` to `be_api` and
   `be_api_slow` (track line included).
3. Reload (no drops): `docker compose exec haproxy1 sh -c 'echo reload |
   socat stdio /var/run/haproxy/admin.sock'` (same on haproxy2), or restart
   one HAProxy at a time — the VIP keeps the other serving.

Verify: `curl -I https://api.alfarid.example/api/v1/store/branding` several
times → `X-Served-By` cycles across app1..app4.

## Sizing (replace with your measured numbers from load-test-report.md)

| Daily orders | App nodes (4 GB RAM, pm.max_children=32) | Postgres | Notes |
|---|---|---|---|
| < 1k | 2 | 1 × 4 GB + PgBouncer 40 | Sentinel Redis on same hosts |
| 1k–10k | 3 | 1 × 8 GB + PgBouncer 40 | This plan's default |
| 10k–50k | 4–6 | 1 × 16 GB + read replica for reports | Split `pgsql_reports` for exports |
| > 50k | 6+ + micro-cache (optional phase A) | Primary + replica | Re-run k6 test #3 to find the new ceiling |

Rule of thumb: 1 app node ≈ `pm.max_children` concurrent PHP requests;
HAProxy queue absorbs bursts (test #8). Scale app nodes when p95 > 1s or
queue > 50 sustained.
