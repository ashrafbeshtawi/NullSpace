#!/bin/bash
# backup-offsite.sh — push the project's recoverable state to a restic
# repository on Backblaze B2 (or any restic-supported backend).
#
# Layers on top of bin/backup-postgres.sh: that script writes a fresh
# pg_dumpall under /var/backups/nullspace. This script then bundles the
# dumps, the host .env, and the small named volumes that hold app state
# (agent_workspace, uptime_kuma_data, letsencrypt) and pushes the lot to
# restic. restic does client-side encryption + block-level dedup, so
# day-2+ runs only upload deltas.
#
# Requirements on the VPS:
#   - restic in PATH (apt install restic)
#   - docker (for the volume-tar step)
#   - /etc/nullspace-backup.env (chmod 600 root:root) with:
#       B2_ACCOUNT_ID=<keyID>
#       B2_ACCOUNT_KEY=<applicationKey>
#       RESTIC_REPOSITORY=b2:<bucket>:<path>
#       RESTIC_PASSWORD=<encryption-key-store-in-pw-manager>
#       BACKUP_HEARTBEAT_URL=<optional Uptime Kuma push URL>
#   - One-time `restic init` against that repo before the first run.
#
# Intended as a daily cron job:
#   30 3 * * * /opt/NullSpace/bin/backup-offsite.sh >> /var/log/nullspace-backup.log 2>&1

set -euo pipefail

CREDS_FILE="${NULLSPACE_BACKUP_ENV:-/etc/nullspace-backup.env}"
PROJECT_DIR="${NULLSPACE_PROJECT_DIR:-/opt/NullSpace}"
LOCAL_BACKUP_DIR="${LOCAL_BACKUP_DIR:-/var/backups/nullspace}"
COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-nullspace}"

# Volumes worth backing up. Anything not listed is recoverable from elsewhere:
#   ollama_data    → multi-GB models, re-pullable via `ollama pull`
#   redis_data     → cache, regenerates itself
#   portainer_data → re-configurable in minutes
#   postgres_data  → handled by the pg_dump path, not raw-tarred (pg dump is
#                    more portable across major versions and smaller).
VOLUMES=(agent_workspace uptime_kuma_data letsencrypt)

if [ ! -r "$CREDS_FILE" ]; then
    echo "error: $CREDS_FILE missing or unreadable" >&2
    exit 1
fi

set -a
. "$CREDS_FILE"
set +a

echo "==> $(date -Iseconds) — refreshing local postgres dump"
"$PROJECT_DIR/bin/backup-postgres.sh"

STAGE=$(mktemp -d /tmp/nullspace-volumes.XXXXXX)
trap 'rm -rf "$STAGE"' EXIT

echo "==> tarring named volumes into $STAGE"
for VOL_SHORT in "${VOLUMES[@]}"; do
    VOL_FULL="${COMPOSE_PROJECT_NAME}_${VOL_SHORT}"
    if ! docker volume inspect "$VOL_FULL" >/dev/null 2>&1; then
        echo "    skipping $VOL_FULL (not present on this host)"
        continue
    fi
    echo "    $VOL_FULL"
    docker run --rm \
        -v "$VOL_FULL":/data:ro \
        -v "$STAGE":/out \
        busybox tar czf "/out/${VOL_SHORT}.tar.gz" -C /data .
done

echo "==> restic backup"
restic backup \
    --tag daily \
    --tag "host:$(hostname -s)" \
    "$LOCAL_BACKUP_DIR" \
    "$PROJECT_DIR/.env" \
    "$STAGE"

echo "==> applying retention (keep 14 daily, 4 weekly, 6 monthly)"
restic forget \
    --tag daily \
    --keep-daily 14 --keep-weekly 4 --keep-monthly 6 \
    --prune

# Optional liveness ping for an Uptime Kuma Push monitor. Skipped if unset.
if [ -n "${BACKUP_HEARTBEAT_URL:-}" ]; then
    curl -fsS --max-time 10 "$BACKUP_HEARTBEAT_URL" >/dev/null || true
fi

echo "==> $(date -Iseconds) — done"
