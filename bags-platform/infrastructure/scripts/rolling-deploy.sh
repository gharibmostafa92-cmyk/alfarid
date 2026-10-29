#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
DC=(docker compose --project-directory "$ROOT" -f "$ROOT/docker-compose.yml")
if [[ ${LOAD_TEST:-0} == 1 ]]; then DC+=(-f "$ROOT/docker-compose.loadtest.yml"); fi
SOCK=/var/run/haproxy/admin.sock
NODES=(app1 app2 app3)

hap() {
  local proxy=$1 command=$2 result
  result=$(printf '%s\n' "$command" | "${DC[@]}" exec -T "$proxy" socat stdio "$SOCK")
  # HAProxy may return exit code 0 for invalid admin commands.
  if [[ -n "$result" ]]; then echo "$proxy: $result" >&2; return 1; fi
}

if [[ ${SKIP_BUILD:-0} != 1 ]]; then "${DC[@]}" build app1; fi
"${DC[@]}" run --rm --no-deps -e DB_HOST=postgres -e DB_PORT=5432 app1 php artisan migrate --force
for node in "${NODES[@]}"; do
  echo "Draining $node on both proxies and both API backends"
  for proxy in haproxy1 haproxy2; do
    for backend in be_api be_api_slow; do hap "$proxy" "set server $backend/$node state drain"; done
  done
  drained=false
  for ((i=0; i<100; i++)); do
    active=0
    for proxy in haproxy1 haproxy2; do
      count=$(printf 'show stat\n' | "${DC[@]}" exec -T "$proxy" socat stdio "$SOCK" |
        awk -F, -v n="$node" '$2 == n && ($1 == "be_api" || $1 == "be_api_slow") {s += $3 + $5} END {print s+0}')
      active=$((active + count))
    done
    if ((active == 0)); then drained=true; break; fi
    sleep 2
  done
  if [[ $drained != true ]]; then echo "Drain timed out; $node remains drained" >&2; exit 1; fi
  for proxy in haproxy1 haproxy2; do
    for backend in be_api be_api_slow; do hap "$proxy" "set server $backend/$node state maint"; done
  done
  "${DC[@]}" up -d --no-deps --no-build --force-recreate "$node"
  ready=false
  for ((i=0; i<60; i++)); do
    if "${DC[@]}" exec -T "$node" curl -fsS http://localhost:8080/health/ready >/dev/null; then ready=true; break; fi
    sleep 2
  done
  if [[ $ready != true ]]; then echo "Readiness failed; $node remains in maintenance" >&2; exit 1; fi
  for proxy in haproxy1 haproxy2; do
    for backend in be_api be_api_slow; do hap "$proxy" "set server $backend/$node state ready"; done
  done
done
"${DC[@]}" up -d --no-deps --no-build --force-recreate worker worker2 media-worker scheduler
echo 'Deploy complete'
