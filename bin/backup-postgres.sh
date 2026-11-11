#!/bin/bash
# backup-postgres.sh — dump all postgres databases to a gzipped file.
#
# Writes /var/backups/nullspace/pg-YYYY-MM-DD.sql.gz using pg_dumpall inside
# the running postgres container. Captures every DB (nullspace, glitchtip,
# dogeclaw, …) plus roles and grants, so a restore reproduces the cluster.
# Keeps the last 14 days; older dumps are pruned automatically.
#
# A failed or truncated dump exits non-zero and never replaces an existing
# dump, so backup-offsite.sh stops before uploading and before the heartbeat.
#
# Intended for cron, e.g. `0 3 * * * /opt/NullSpace/bin/backup-postgres.sh`.

set -eo pipefail

BACKUP_DIR="/var/backups/nullspace"
RETENTION_DAYS=14
STAMP="$(date +%F)"

mkdir -p "$BACKUP_DIR"

cd /opt/NullSpace

# Load POSTGRES_USER from the project .env so we don't hardcode it.
set -a
. ./.env
set +a
set -u

OUT="$BACKUP_DIR/pg-$STAMP.sql.gz"
# Not matched by the pg-*.sql.gz globs used for pruning and restores.
TMP="$OUT.partial"
trap 'rm -f "$TMP"' EXIT

echo "==> dumping postgres to $OUT"
docker compose exec -T postgres pg_dumpall -U "$POSTGRES_USER" | gzip > "$TMP"

gzip -t "$TMP"
# pg_dumpall always ends with this line; without it the dump is incomplete.
TAIL="$(gzip -dc "$TMP" | tail -n 20)"
if [[ "$TAIL" != *"PostgreSQL database cluster dump complete"* ]]; then
    echo "error: dump is incomplete, keeping the previous $OUT (if any)" >&2
    exit 1
fi
mv "$TMP" "$OUT"

echo "==> pruning dumps older than $RETENTION_DAYS days"
find "$BACKUP_DIR" -name 'pg-*.sql.gz' -mtime "+$RETENTION_DAYS" -delete

echo "==> done. current backups:"
ls -lh "$BACKUP_DIR"
