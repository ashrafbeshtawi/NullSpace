#!/bin/bash
# shell.sh — drop into a shell inside a running compose service.
#
# Usage: ./bin/shell.sh <service>     e.g. ./bin/shell.sh postgres
#
# Tries bash first, falls back to sh, so it works against alpine-based
# images (postgres, redis) and full-distro images alike.

set -e

if [ -z "$1" ]; then
  echo "usage: $0 <service>" >&2
  echo "services:" >&2
  cd /opt/NullSpace
  docker compose ps --services >&2
  exit 1
fi

cd /opt/NullSpace
docker compose exec "$1" sh -lc 'command -v bash >/dev/null && exec bash || exec sh'
