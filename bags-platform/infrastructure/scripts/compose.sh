#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
FILES=(-f "$ROOT/docker-compose.yml" -f "$ROOT/docker-compose.monitoring.yml" -f "$ROOT/docker-compose.vip.yml")
if [[ ${LOAD_TEST:-0} == 1 ]]; then FILES+=(-f "$ROOT/docker-compose.loadtest.yml"); fi
exec docker compose --project-directory "$ROOT" "${FILES[@]}" "$@"
