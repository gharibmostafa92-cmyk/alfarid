#!/usr/bin/env bash
# Nightly pg_dump -Fc to the private R2 bucket, keep last 14. Restore tested in runbook.
# C1 hardening:
# - Atomic write: dump to a .tmp file, verify it, then rename. An interrupted
#   dump can never match the alfarid-*.dump retention pattern or be picked by
#   verify-backup.sh (which sorts names alphabetically).
# - Verify with pg_restore --list before publishing; exit non-zero on failure
#   so the compose loop container exits loudly instead of sleeping silently.
# - R2 mandatory when REQUIRE_R2_BACKUP=1 (set in docker-compose.yml).
# - Touch a last-success timestamp the monitor can alert on ("backup too old").
set -euo pipefail
BACKUP_DIR=${BACKUP_DIR:-/backups}
RETENTION=${RETENTION:-14}
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
FILE="${BACKUP_DIR}/alfarid-${STAMP}.dump"
TMP="${FILE}.tmp"
mkdir -p "${BACKUP_DIR}"
METRICS_DIR=${BACKUP_METRICS_DIR:-${BACKUP_DIR}}
mkdir -p "${METRICS_DIR}"
write_metric() { # $1=file $2=name $3=value  (node-exporter textfile format, atomic)
  printf '# TYPE %s gauge\n%s %s\n' "$2" "$2" "$3" > "${METRICS_DIR}/$1.$$" && mv "${METRICS_DIR}/$1.$$" "${METRICS_DIR}/$1"
}
cleanup() {
  rc=$?
  rm -f "${TMP}"
  [ "$rc" -eq 0 ] || write_metric alfarid_backup_run.prom alfarid_backup_last_run_success 0
}
trap cleanup EXIT
pg_dump -h "${PGHOST:?}" -p "${PGPORT:-5432}" -U "${PGUSER:?}" -Fc "${PGDATABASE:-alfarid}" -f "${TMP}"
pg_restore --list "${TMP}" >/dev/null
mv "${TMP}" "${FILE}"
if [ -n "${R2_BACKUP_SYNC:-}" ]; then
  aws s3 cp "${FILE}" "s3://${R2_BACKUP_BUCKET:?}/postgres-backups/$(basename "${FILE}")" --endpoint-url "${R2_ENDPOINT:?}"
  # Prune only after a successful upload. ISO timestamps sort oldest first.
  aws s3api list-objects-v2 --bucket "$R2_BACKUP_BUCKET" --prefix postgres-backups/ \
    --endpoint-url "$R2_ENDPOINT" --query 'Contents[].Key' --output text |
    tr '\t' '\n' | grep '^postgres-backups/alfarid-.*\.dump$' | sort -r |
    tail -n +"$((RETENTION + 1))" | while IFS= read -r key; do
      aws s3api delete-object --bucket "$R2_BACKUP_BUCKET" --key "$key" --endpoint-url "$R2_ENDPOINT" >/dev/null
    done
elif [ "${REQUIRE_R2_BACKUP:-0}" = "1" ]; then
  echo "R2 backup is required in production (REQUIRE_R2_BACKUP=1) but R2_BACKUP_SYNC is empty." >&2
  exit 1
else
  echo "WARNING: R2_BACKUP_SYNC is empty; backup kept only on the local volume." >&2
fi
ls -1t "${BACKUP_DIR}"/alfarid-*.dump | tail -n +"$((RETENTION + 1))" | xargs -r rm -f
date -u +%Y%m%dT%H%M%SZ > "${BACKUP_DIR}/.last-success"
write_metric alfarid_backup_run.prom alfarid_backup_last_run_success 1
write_metric alfarid_backup_success.prom alfarid_backup_last_success_timestamp_seconds "$(date -u +%s)"
echo "backup written: ${FILE}"
