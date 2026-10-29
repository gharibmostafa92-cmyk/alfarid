FROM postgres:16-alpine
RUN apk add --no-cache pgbouncer openssl bash aws-cli
COPY infrastructure/docker/postgres-start.sh infrastructure/docker/pgbouncer-start.sh /opt/
COPY infrastructure/scripts/pg-backup.sh /opt/pg-backup.sh
RUN chmod 755 /opt/*.sh
