#!/bin/sh
set -eu
case "$DB_USERNAME" in *[!A-Za-z0-9_]*|'') echo 'Use an alphanumeric DB_USERNAME' >&2; exit 1;; esac
case "$DB_PASSWORD" in *[!A-Za-z0-9_-]*|'') echo 'Use a URL-safe DB_PASSWORD' >&2; exit 1;; esac
mkdir -p /tmp/pgbouncer/tls
cp /opt/pgbouncer.ini /tmp/pgbouncer/pgbouncer.ini
printf '\nadmin_users = %s\nstats_users = %s\n' "$DB_USERNAME" "$DB_USERNAME" >> /tmp/pgbouncer/pgbouncer.ini
printf '"%s" "%s"\n' "$DB_USERNAME" "$DB_PASSWORD" > /tmp/pgbouncer/userlist.txt
openssl req -x509 -newkey rsa:2048 -nodes -days 365 -subj /CN=pgbouncer \
  -keyout /tmp/pgbouncer/tls/server.key -out /tmp/pgbouncer/tls/server.crt 2>/dev/null
chown -R postgres:postgres /tmp/pgbouncer
chmod 700 /tmp/pgbouncer
chmod 600 /tmp/pgbouncer/userlist.txt /tmp/pgbouncer/tls/server.key
exec su-exec postgres pgbouncer /tmp/pgbouncer/pgbouncer.ini
