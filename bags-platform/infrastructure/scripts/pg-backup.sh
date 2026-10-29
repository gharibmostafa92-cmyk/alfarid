#!/usr/bin/env bash
# Nightly pg_dump -Fc to the private R2 bucket, keep last 14. Restore tested in runbook.
set -euo pipefail
BACKUP_DIR=${BACKUP_DIR:-/backups}
RETENTION=${RETENTION:-14}
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
FILE="${BACKUP_DIR}/alfarid-${STAMP}.dump"
mkdir -p "${BACKUP_DIR}"
pg_dump -h "${PGHOST:?}" -p "${PGPORT:-5432}" -U "${PGUSER:?}" -Fc "${PGDATABASE:-alfarid}" -f "${FILE}"
if [ -n "${R2_BACKUP_SYNC:-}" ]; then
  aws s3 cp "${FILE}" "s3://${R2_BACKUP_BUCKET:?}/postgres-backups/$(basename "${FILE}")" --endpoint-url "${R2_ENDPOINT:?}"
  # Prune only after a successful upload. ISO timestamps sort oldest first.
  aws s3api list-objects-v2 --bucket "$R2_BACKUP_BUCKET" --prefix postgres-backups/ \
    --endpoint-url "$R2_ENDPOINT" --query 'Contents[].Key' --output text |
    tr '\t' '\n' | grep '^postgres-backups/alfarid-.*\.dump$' | sort -r |
    tail -n +"$((RETENTION + 1))" | while IFS= read -r key; do
      aws s3api delete-object --bucket "$R2_BACKUP_BUCKET" --key "$key" --endpoint-url "$R2_ENDPOINT" >/dev/null
    done
fi
ls -1t "${BACKUP_DIR}"/alfarid-*.dump | tail -n +"$((RETENTION + 1))" | xargs -r rm -f
echo "backup written: ${FILE}"
