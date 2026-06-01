#!/bin/bash
# cleanup.sh — reclaim disk by removing unused images and build cache.
#
# Safe to run on a VPS where containers are managed by docker-compose:
#   - `docker image prune -af` removes images not referenced by any existing
#     container (running or stopped). Compose keeps stopped containers around,
#     so currently-deployed images survive even if their container is paused
#     or exited (e.g. dogeclaw-migrations).
#   - `docker builder prune -af` clears the BuildKit cache. The next build
#     will be slower, but the cache rebuilds itself.
# Prints `docker system df` before and after so reclaim is visible.

set -e

echo "==> docker system df (before)"
docker system df
echo ""

echo "==> docker image prune -af"
docker image prune -af
echo ""

echo "==> docker builder prune -af"
docker builder prune -af
echo ""

echo "==> docker system df (after)"
docker system df
