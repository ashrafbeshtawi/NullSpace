#!/bin/bash
# restore-postgres.sh — restore a pg_dumpall backup produced by backup-postgres.sh.
#
# Usage: ./bin/restore-postgres.sh /var/backups/nullspace/pg-2026-05-26.sql.gz
#
# DESTRUCTIVE: replays the dump into the running postgres container, which
# will overwrite any conflicting databases/roles. Requires a typed
# confirmation (NULLSPACE_RESTORE_YES=1 skips it — restore-offsite.sh has
# already asked). Stops every other service first so nothing holds a
# connection or races writes against the replay, then restarts them after.

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

if [ "${NULLSPACE_RESTORE_YES:-}" != "1" ]; then
  echo "About to restore $DUMP into the running postgres container."
  echo "This will OVERWRITE existing data. Type 'yes' to continue:"
  read -r CONFIRM
  if [ "$CONFIRM" != "yes" ]; then
    echo "aborted."
    exit 1
  fi
fi

# Every running service except postgres itself, traefik, and admin (this
# script may be running from the admin panel — stopping admin would kill
# it). Derived at runtime so a new database consumer never has to be added
# here by hand; a hardcoded list drifted out of sync with cluster-init.sql.
APPS=$(docker compose ps --services --status running | grep -vxE 'postgres|traefik|admin' || true)

echo "==> stopping services: $(echo $APPS)"
if [ -n "$APPS" ]; then
  docker compose stop $APPS
fi

echo "==> restoring dump"
gunzip -c "$DUMP" | docker compose exec -T postgres psql -U "$POSTGRES_USER" -d postgres

echo "==> restarting services"
if [ -n "$APPS" ]; then
  docker compose start $APPS
fi

echo "==> done."
