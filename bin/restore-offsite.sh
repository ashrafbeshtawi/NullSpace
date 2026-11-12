#!/bin/bash
# restore-offsite.sh — recover from the restic repository on B2.
#
# Usage:
#   restore-offsite.sh list                       List all snapshots
#   restore-offsite.sh check                      Verify repo integrity
#   restore-offsite.sh env  <snapshot>            Restore .env only
#   restore-offsite.sh pg   <snapshot>            Restore latest pg dump and replay it
#   restore-offsite.sh full <snapshot>            Full disaster recovery
#
# <snapshot> can be a restic snapshot id (e.g. ab12cd34) or the literal
# `latest`. `pg` and `full` are destructive and will prompt before
# running. Set NULLSPACE_RESTORE_YES=1 in the env to skip prompts (for
# unattended disaster scripts).

set -euo pipefail

CREDS_FILE="${NULLSPACE_BACKUP_ENV:-/etc/nullspace-backup.env}"
PROJECT_DIR="${NULLSPACE_PROJECT_DIR:-/opt/NullSpace}"
COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-nullspace}"
VOLUMES=(agent_workspace uptime_kuma_data letsencrypt)

if [ ! -r "$CREDS_FILE" ]; then
    echo "error: $CREDS_FILE missing or unreadable" >&2
    exit 1
fi

set -a
. "$CREDS_FILE"
set +a

usage() {
    sed -n '2,/^$/p' "$0" | sed 's/^# \{0,1\}//'
    exit 1
}

confirm() {
    local prompt="${1:-Proceed?}"
    if [ "${NULLSPACE_RESTORE_YES:-}" = "1" ]; then
        return 0
    fi
    echo -n "$prompt Type 'yes' to continue: "
    read -r reply
    [ "$reply" = "yes" ] || { echo "aborted."; exit 1; }
}

cmd_list() {
    restic snapshots
}

cmd_check() {
    restic check
}

restore_to_staging() {
    # Restore one snapshot (optionally restricted by --include path) into
    # a fresh /tmp dir and echo the dir on stdout.
    local snap="$1"
    shift
    local target
    target=$(mktemp -d /tmp/nullspace-restore.XXXXXX)
    restic restore "$snap" --target "$target" "$@" >/dev/null
    echo "$target"
}

cmd_env() {
    local snap="${1:?snapshot id or 'latest' required}"
    local target
    target=$(restore_to_staging "$snap" --include "$PROJECT_DIR/.env")
    trap 'rm -rf "$target"' EXIT

    local src="$target$PROJECT_DIR/.env"
    if [ ! -f "$src" ]; then
        echo "error: $PROJECT_DIR/.env not found in snapshot $snap" >&2
        exit 1
    fi

    confirm "Overwrite $PROJECT_DIR/.env with version from snapshot $snap?"
    cp "$src" "$PROJECT_DIR/.env"
    echo "==> .env restored from snapshot $snap"
}

cmd_pg() {
    local snap="${1:?snapshot id or 'latest' required}"
    local target
    target=$(restore_to_staging "$snap" --include /var/backups/nullspace)
    trap 'rm -rf "$target"' EXIT

    local dump
    dump=$(find "$target/var/backups/nullspace" -name 'pg-*.sql.gz' 2>/dev/null | sort | tail -1)
    if [ -z "$dump" ]; then
        echo "error: no pg dump found in snapshot $snap" >&2
        exit 1
    fi

    echo "Found dump: $dump"
    echo "WARNING: pg_dumpall replay will recreate databases and roles."
    echo "         Existing data in the running postgres container will be lost."
    confirm "Restore this dump into the running postgres container?"

    # Same replay as the local path — restore-postgres.sh stops every other
    # service around it so nothing holds a connection during the replay.
    NULLSPACE_RESTORE_YES=1 "$PROJECT_DIR/bin/restore-postgres.sh" "$dump"
    echo "==> postgres restored from $dump"
}

restore_volume() {
    local vol_short="$1"
    local target="$2"
    local vol_full="${COMPOSE_PROJECT_NAME}_${vol_short}"
    local tar
    tar=$(find "$target" -name "${vol_short}.tar.gz" 2>/dev/null | head -1)
    if [ -z "$tar" ]; then
        echo "    skip $vol_short (not in snapshot)"
        return 0
    fi
    # A volume can't be removed while a container (even a stopped one) still
    # references it, so drop its users first; the final `up -d` recreates them.
    local users
    users=$(docker ps -aq --filter "volume=$vol_full")
    if [ -n "$users" ]; then
        docker rm -f $users >/dev/null
    fi
    docker volume rm "$vol_full" >/dev/null 2>&1 || true
    docker volume create "$vol_full" >/dev/null
    docker run --rm \
        -v "$vol_full":/data \
        -v "$tar":/backup.tar.gz:ro \
        busybox tar xzf /backup.tar.gz -C /data
    echo "    $vol_full restored from $tar"
}

cmd_full() {
    local snap="${1:?snapshot id or 'latest' required}"

    local compose=(docker compose -f docker-compose.yml -f docker-compose.prod.yml)

    echo "Full disaster recovery from snapshot $snap. This will:"
    echo "  1. Stop every service except admin and traefik"
    echo "  2. Restore .env (overwriting current)"
    echo "  3. Recreate and restore named volumes: ${VOLUMES[*]}"
    echo "  4. Bring postgres up and replay the latest pg dump in the snapshot"
    echo "  5. Bring the rest of the stack up, admin last"
    confirm

    # Keep a copy of the output on the host: restoring the letsencrypt volume
    # recreates traefik, which cuts the admin panel's live output.
    local log="/var/backups/nullspace/restore-full-$(date +%Y%m%d-%H%M%S).log"
    mkdir -p /var/backups/nullspace
    exec > >(tee -a "$log") 2>&1
    echo "==> logging to $log"

    local target
    target=$(restore_to_staging "$snap")
    trap 'rm -rf "$target"' EXIT

    cd "$PROJECT_DIR"

    # Not `down`: this script may be running from the admin panel, and
    # stopping admin would kill it halfway (same trap restore-postgres.sh
    # avoids). traefik stays up so the panel keeps streaming output.
    local apps
    apps=$("${compose[@]}" ps --services --status running | grep -vxE 'admin|traefik' || true)
    echo "==> stopping services: $(echo $apps)"
    if [ -n "$apps" ]; then
        "${compose[@]}" stop $apps
    fi

    echo "==> restoring .env"
    if [ -f "$target$PROJECT_DIR/.env" ]; then
        cp "$target$PROJECT_DIR/.env" "$PROJECT_DIR/.env"
    else
        echo "    .env not in snapshot — leaving current in place"
    fi
    # The pg steps need POSTGRES_USER from the (restored) .env; the web path
    # does not export it. Sourced without -u, like backup-postgres.sh does.
    set +u -a
    . "$PROJECT_DIR/.env"
    set -u +a

    echo "==> restoring named volumes"
    for VOL_SHORT in "${VOLUMES[@]}"; do
        restore_volume "$VOL_SHORT" "$target"
    done

    echo "==> bringing postgres up"
    "${compose[@]}" up -d postgres
    # Wait until postgres reports healthy.
    for _ in $(seq 1 30); do
        if "${compose[@]}" exec -T postgres pg_isready -U "$POSTGRES_USER" >/dev/null 2>&1; then
            break
        fi
        sleep 1
    done

    local dump
    dump=$(find "$target/var/backups/nullspace" -name 'pg-*.sql.gz' 2>/dev/null | sort | tail -1)
    if [ -n "$dump" ]; then
        echo "==> replaying $dump"
        gunzip -c "$dump" | "${compose[@]}" exec -T postgres psql -U "$POSTGRES_USER" -d postgres
    else
        echo "    no pg dump in snapshot — skipping postgres restore"
    fi

    echo "==> bringing rest of stack up"
    "${compose[@]}" up -d $("${compose[@]}" config --services | grep -vx admin)

    # Last on purpose: if the restored .env changes admin's config, compose
    # recreates admin, which ends this script.
    echo "==> done. Verify with: docker compose ps (output also in $log)"
    "${compose[@]}" up -d admin
}

CMD="${1:-}"
shift || true

case "$CMD" in
    list)  cmd_list "$@" ;;
    check) cmd_check "$@" ;;
    env)   cmd_env "$@" ;;
    pg)    cmd_pg "$@" ;;
    full)  cmd_full "$@" ;;
    *)     usage ;;
esac
