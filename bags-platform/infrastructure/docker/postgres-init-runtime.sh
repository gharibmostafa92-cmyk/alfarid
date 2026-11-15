#!/bin/sh
set -eu

# [CRITICAL-01] Create the non-owner runtime role so app/worker nodes cannot
# alter schema or bypass triggers, while migrate owns tables as POSTGRES_USER.
runtime_user="${DB_RUNTIME_USERNAME:?set DB_RUNTIME_USERNAME}"
runtime_pass="${DB_RUNTIME_PASSWORD:?set DB_RUNTIME_PASSWORD}"
db_name="${POSTGRES_DB:-alfarid}"

case "$runtime_user" in *[!A-Za-z0-9_]*|'') echo 'Invalid DB_RUNTIME_USERNAME' >&2; exit 1;; esac
if [ "$runtime_user" = "$POSTGRES_USER" ]; then
  echo 'DB_RUNTIME_USERNAME must differ from DB_MIGRATION_USERNAME (POSTGRES_USER)' >&2
  exit 1
fi

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$db_name" \
  -v runtime_user="$runtime_user" -v runtime_pass="$runtime_pass" <<'EOSQL'
SELECT format(
  'CREATE ROLE %I WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT PASSWORD %L',
  :'runtime_user', :'runtime_pass'
)
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'runtime_user')
\gexec

SELECT format('ALTER ROLE %I WITH PASSWORD %L', :'runtime_user', :'runtime_pass')
\gexec

GRANT CONNECT ON DATABASE current_database() TO :"runtime_user";
GRANT USAGE ON SCHEMA public TO :"runtime_user";
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO :"runtime_user";
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO :"runtime_user";
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO :"runtime_user";
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO :"runtime_user";
EOSQL
