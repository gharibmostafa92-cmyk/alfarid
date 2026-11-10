#!/bin/sh
set -eu
mkdir -p /tmp/postgres-tls
openssl req -x509 -newkey rsa:2048 -nodes -days 365 -subj /CN=postgres \
  -keyout /tmp/postgres-tls/server.key -out /tmp/postgres-tls/server.crt 2>/dev/null
chown -R postgres:postgres /tmp/postgres-tls
chmod 600 /tmp/postgres-tls/server.key
exec docker-entrypoint.sh postgres -c ssl=on \
  -c ssl_cert_file=/tmp/postgres-tls/server.crt -c ssl_key_file=/tmp/postgres-tls/server.key \
  -c shared_buffers="${PG_SHARED_BUFFERS:-512MB}" \
  -c effective_cache_size="${PG_EFFECTIVE_CACHE_SIZE:-2GB}" \
  -c max_connections="${PG_MAX_CONNECTIONS:-150}"
