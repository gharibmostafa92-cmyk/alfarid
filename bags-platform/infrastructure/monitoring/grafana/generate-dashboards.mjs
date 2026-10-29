import { mkdirSync, writeFileSync } from 'node:fs';
const directory = new URL('./dashboards/', import.meta.url);
mkdirSync(directory, { recursive: true });
const definitions = {
  'load-balancer': [
    ['Requests / second by backend', 'sum by (proxy) (rate(haproxy_backend_http_responses_total[1m]))', 'reqps'],
    ['App server health (1 = UP, 0 = unavailable)', 'haproxy_server_status{proxy="be_api",state="UP"}', 'short'],
    ['Queued requests', 'haproxy_backend_current_queue', 'short'],
    ['Response time average (HAProxy, last 1024 requests)', 'haproxy_backend_response_time_average_seconds', 's'],
    ['k6 p95 (only during tests; not an HAProxy histogram)', 'k6_http_req_duration_p95', 's'],
    ['4xx and 5xx / second', 'sum by (proxy, code) (rate(haproxy_backend_http_responses_total{code=~"4xx|5xx"}[1m]))', 'reqps'],
    ['Edge denied requests / second (includes 429)', 'rate(haproxy_frontend_requests_denied_total{proxy="fe_public"}[1m])', 'reqps'],
  ],
  application: [
    ['Container CPU cores', 'sum by (name) (rate(container_cpu_usage_seconds_total{name=~".*alfarid.*"}[1m]))', 'short'],
    ['Container working memory', 'container_memory_working_set_bytes{name=~".*alfarid.*"}', 'bytes'],
    ['Pending database jobs', 'alfarid_queue_pending', 'short'],
    ['Failed database jobs', 'alfarid_queue_failed', 'short'],
    ['Host CPU usage', '1 - avg(rate(node_cpu_seconds_total{mode="idle"}[1m]))', 'percentunit'],
    ['Host available memory', 'node_memory_MemAvailable_bytes', 'bytes'],
  ],
  data: [
    ['PgBouncer server connections', 'pgbouncer_pools_server_active_connections', 'short'],
    ['PgBouncer waiting clients', 'pgbouncer_pools_client_waiting_connections', 'short'],
    ['PostgreSQL connections', 'pg_stat_database_numbackends', 'short'],
    ['PostgreSQL locks', 'pg_locks_count', 'short'],
    ['PostgreSQL deadlocks / second', 'rate(pg_stat_database_deadlocks[5m])', 'ops'],
    ['Redis used memory', 'redis_memory_used_bytes', 'bytes'],
    ['Redis hit ratio', 'rate(redis_keyspace_hits_total[5m]) / clamp_min(rate(redis_keyspace_hits_total[5m]) + rate(redis_keyspace_misses_total[5m]), 0.001)', 'percentunit'],
  ],
};
for (const [name, rows] of Object.entries(definitions)) {
  const panels = rows.map(([title, expr, unit], i) => ({
    id: i + 1, type: 'timeseries', title,
    datasource: { type: 'prometheus', uid: 'prometheus' },
    gridPos: { x: (i % 2) * 12, y: Math.floor(i / 2) * 8, w: 12, h: 8 },
    targets: [{ refId: 'A', expr, legendFormat: '__auto' }],
    fieldConfig: { defaults: { unit }, overrides: [] },
  }));
  writeFileSync(new URL(`${name}.json`, directory), JSON.stringify({
    uid: `alfarid-${name}`, title: `Alfarid / ${name}`, schemaVersion: 39,
    version: 1, refresh: '5s', time: { from: 'now-15m', to: 'now' }, panels,
  }, null, 2) + '\n');
}
writeFileSync(new URL('logs.json', directory), JSON.stringify({
  uid: 'alfarid-logs', title: 'Alfarid / logs', schemaVersion: 39, version: 1, refresh: '5s',
  time: { from: 'now-15m', to: 'now' },
  templating: { list: [{ name: 'request_id', type: 'textbox', label: 'Request ID', current: { text: '', value: '' } }] },
  panels: [{ id: 1, type: 'logs', title: 'Container logs / request ID', gridPos: { x: 0, y: 0, w: 24, h: 18 },
    datasource: { type: 'loki', uid: 'loki' },
    targets: [{ refId: 'A', expr: '{container=~".*alfarid.*"} |= "$request_id"' }] }],
}, null, 2) + '\n');
