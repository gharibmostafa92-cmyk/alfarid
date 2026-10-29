#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
DC=(docker compose --project-directory "$ROOT" -f "$ROOT/docker-compose.yml")
RESTORE_DB=alfarid_restore_$(date -u +%Y%m%d%H%M%S)
EXPECTED=$("${DC[@]}" exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d alfarid -tAc "SELECT count(*) FROM products"')
"${DC[@]}" run --rm --no-deps --entrypoint /opt/pg-backup.sh backup
"${DC[@]}" exec -T postgres sh -c 'createdb -U "$POSTGRES_USER" "$1"' sh "$RESTORE_DB"
ACTUAL=$("${DC[@]}" run --rm --no-deps --entrypoint sh -e PGDATABASE="$RESTORE_DB" backup -c \
  'set -eu; dump=$(ls -1 /backups/alfarid-*.dump | sort -r | head -n 1); pg_restore --no-owner --no-privileges --dbname "$PGDATABASE" "$dump"; psql -tAc "SELECT count(*) FROM products"')
if [[ "$ACTUAL" != "$EXPECTED" ]]; then echo "Restore count mismatch: $EXPECTED vs $ACTUAL" >&2; exit 1; fi
echo "PASS: restored $EXPECTED products into isolated database $RESTORE_DB"
echo "The restored database is retained for inspection. No live database was replaced."
