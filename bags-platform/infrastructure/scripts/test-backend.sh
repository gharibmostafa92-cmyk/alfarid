#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
DC=(docker compose --project-directory "$ROOT" -f "$ROOT/docker-compose.yml" -f "$ROOT/docker-compose.tests.yml")
"${DC[@]}" up -d --wait postgres-test
"${DC[@]}" build tests
"${DC[@]}" run --rm --no-deps tests "$@"
