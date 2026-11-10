#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
DC=(docker compose --project-directory "$ROOT" -f "$ROOT/docker-compose.yml")
RESTORE_DB=alfarid_restore_$(date -u +%Y%m%d%H%M%S)_$$_$RANDOM
created=0
cleanup() {
  local status=$?
  if [[ "$created" == 1 ]]; then
    if [[ "${KEEP_RESTORE_DB:-0}" == 1 ]]; then
      echo "Retained isolated database $RESTORE_DB for inspection."
    elif ! "${DC[@]}" exec -T postgres sh -c 'dropdb -U "$POSTGRES_USER" "$1"' sh "$RESTORE_DB"; then
      echo "Could not drop isolated database $RESTORE_DB." >&2
      status=1
    fi
  fi
  exit "$status"
}
trap cleanup EXIT
"${DC[@]}" exec -T postgres sh -c 'createdb -U "$POSTGRES_USER" "$1"' sh "$RESTORE_DB"
created=1
# Exercise the existing backup without rotating backups or comparing against live writes.
"${DC[@]}" run -T --rm --no-deps --entrypoint sh backup -c '
  set -eu
  export LC_ALL=C
  dump=
  for file in "${BACKUP_DIR:-/backups}"/alfarid-*.dump; do
    [ -f "$file" ] && dump=$file
  done
  [ -n "$dump" ] || { echo "No completed local backup found." >&2; exit 1; }
  echo "Verifying $dump" >&2
  cat "$dump"
' | "${DC[@]}" exec -T postgres sh -c '
  pg_restore -U "$POSTGRES_USER" --no-owner --no-privileges --exit-on-error --single-transaction --dbname "$1"
' sh "$RESTORE_DB"
"${DC[@]}" exec -T postgres sh -c '
  psql -U "$POSTGRES_USER" -d "$1" -v ON_ERROR_STOP=1 -q -c "
    SELECT 1 FROM public.products LIMIT 1;
    SELECT 1 FROM public.orders LIMIT 1;
    SELECT 1 FROM public.order_items LIMIT 1;
    SELECT 1 FROM public.stock_movements LIMIT 1;
    SELECT 1 FROM public.migrations LIMIT 1;
  " >/dev/null
' sh "$RESTORE_DB"
echo "PASS: restored the latest local backup into $RESTORE_DB and read five critical tables."
