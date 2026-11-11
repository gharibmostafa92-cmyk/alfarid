#!/bin/sh
set -eu
umask 077
case "$DB_USERNAME" in *[!A-Za-z0-9_]*|'') echo 'Use an alphanumeric DB_USERNAME' >&2; exit 1;; esac
case "$DB_PASSWORD" in *[!A-Za-z0-9_-]*|'') echo 'Use a URL-safe DB_PASSWORD' >&2; exit 1;; esac
mkdir -p /tmp/pgbouncer/tls
cp /opt/pgbouncer.ini /tmp/pgbouncer/pgbouncer.ini
printf '\nstats_users = %s\n' "$DB_USERNAME" >> /tmp/pgbouncer/pgbouncer.ini
printf '"%s" "%s"\n' "$DB_USERNAME" "$DB_PASSWORD" > /tmp/pgbouncer/userlist.txt
if [ "${DB_SSLMODE:-require}" = verify-full ]; then
  cp /run/alfarid-db-tls/server.crt /tmp/pgbouncer/tls/server.crt
  cp /run/alfarid-db-tls/server.key /tmp/pgbouncer/tls/server.key
  ca_cert=/run/alfarid-db-ca/root.crt
else
  openssl req -x509 -newkey rsa:2048 -nodes -days 365 -subj /CN=pgbouncer \
    -keyout /tmp/pgbouncer/tls/server.key -out /tmp/pgbouncer/tls/server.crt 2>/dev/null
  ca_cert=${PGSSLCACERT:-}
fi
if [ -n "$ca_cert" ]; then
  cp "$ca_cert" /tmp/pgbouncer/tls/root.crt
  sed -i 's/^server_tls_sslmode = .*/server_tls_sslmode = verify-full/' /tmp/pgbouncer/pgbouncer.ini
  printf 'server_tls_ca_file = /tmp/pgbouncer/tls/root.crt\n' >> /tmp/pgbouncer/pgbouncer.ini
fi
chown -R postgres:postgres /tmp/pgbouncer
chmod 700 /tmp/pgbouncer
chmod 600 /tmp/pgbouncer/userlist.txt /tmp/pgbouncer/tls/server.key
exec su-exec postgres pgbouncer /tmp/pgbouncer/pgbouncer.ini
