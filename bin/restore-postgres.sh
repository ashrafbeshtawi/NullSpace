#!/bin/bash
# restore-postgres.sh — restore a pg_dumpall backup produced by backup-postgres.sh.
#
# Usage: ./bin/restore-postgres.sh /var/backups/nullspace/pg-2026-05-26.sql.gz
#
# DESTRUCTIVE: replays the dump into the running postgres container, which
# will overwrite any conflicting databases/roles. Requires a typed
# confirmation. Stops dependent services first so they don't race writes
# against the restore, then restarts them after.

set -e

if [ -z "$1" ]; then
  echo "usage: $0 <path-to-pg-dump.sql.gz>" >&2
  exit 1
fi

DUMP="$1"

if [ ! -f "$DUMP" ]; then
  echo "error: $DUMP not found" >&2
  exit 1
fi

cd /opt/NullSpace

set -a
. ./.env
set +a

echo "About to restore $DUMP into the running postgres container."
echo "This will OVERWRITE existing data. Type 'yes' to continue:"
read -r CONFIRM
if [ "$CONFIRM" != "yes" ]; then
  echo "aborted."
  exit 1
fi

echo "==> stopping app services"
docker compose stop glitchtip glitchtip-worker dogeclaw kiwelt || true

echo "==> restoring dump"
gunzip -c "$DUMP" | docker compose exec -T postgres psql -U "$POSTGRES_USER" -d postgres

echo "==> restarting app services"
docker compose start glitchtip glitchtip-worker dogeclaw kiwelt

echo "==> done."
