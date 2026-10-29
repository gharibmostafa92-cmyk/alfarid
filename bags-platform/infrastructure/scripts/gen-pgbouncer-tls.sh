#!/usr/bin/env bash
# One-time self-signed cert so PgBouncer can serve TLS (AppServiceProvider
# requires DB_SSLMODE=require in production).
set -euo pipefail
OUT=${1:-bags-platform/infrastructure/pgbouncer/tls}
mkdir -p "${OUT}"
openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -subj "/CN=pgbouncer" \
  -keyout "${OUT}/server.key" -out "${OUT}/server.crt"
chmod 600 "${OUT}/server.key"
echo "wrote ${OUT}/server.key and ${OUT}/server.crt"
