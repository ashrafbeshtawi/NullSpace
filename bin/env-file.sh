#!/bin/bash
# env-file.sh — read or replace the project .env (admin panel's .env editor).
#
#   env-file.sh read    print .env to stdout
#   env-file.sh write   replace .env with stdin; the previous version is kept
#                       as .env.bak. stdin is buffered to a temp file first, so
#                       a failed upload never leaves a truncated .env behind.
#                       Overwriting in place keeps the file's owner and mode.

set -euo pipefail

ENV_FILE="$(cd "$(dirname "$0")/.." && pwd)/.env"

case "${1:-}" in
    read)
        cat "$ENV_FILE"
        ;;
    write)
        tmp=$(mktemp)
        trap 'rm -f "$tmp"' EXIT
        cat > "$tmp"
        if [ -f "$ENV_FILE" ]; then
            cp -p "$ENV_FILE" "$ENV_FILE.bak"
        fi
        cat "$tmp" > "$ENV_FILE"
        echo "==> $ENV_FILE written ($(wc -c < "$ENV_FILE") bytes), previous copy at $ENV_FILE.bak"
        ;;
    *)
        echo "usage: $0 read|write" >&2
        exit 2
        ;;
esac
