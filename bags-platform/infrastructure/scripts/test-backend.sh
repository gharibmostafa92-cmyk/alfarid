#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
DC=(docker compose --project-directory "$ROOT" -f "$ROOT/docker-compose.yml" -f "$ROOT/docker-compose.tests.yml")
# Do not run concurrently: the suite resets only alfarid_testing.
"${DC[@]}" exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '\''alfarid_testing'\''" | grep -q 1 || createdb -U "$POSTGRES_USER" alfarid_testing'
"${DC[@]}" build tests
"${DC[@]}" run --rm --no-deps tests "$@"
