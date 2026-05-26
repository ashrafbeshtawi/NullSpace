#!/bin/bash
# fix-docker-api.sh — one-shot VPS bootstrap fix.
#
# Docker Engine 26+ ships with a default minimum API version (v1.44) that
# rejects older clients/libraries outright. Some tooling in this stack still
# speaks the legacy v1.24 API and gets hard-rejected after a fresh install or
# a Docker upgrade.
#
# This script writes a systemd drop-in that sets DOCKER_MIN_API_VERSION=1.24,
# restarts the Docker daemon, and brings the compose stack back up. Run once
# on a fresh VPS (or after a Docker upgrade that re-introduces the strict
# default). Requires root.

set -e

mkdir -p /etc/systemd/system/docker.service.d

cat > /etc/systemd/system/docker.service.d/min_api_version.conf <<'EOF'
[Service]
Environment="DOCKER_MIN_API_VERSION=1.24"
EOF

systemctl daemon-reload
systemctl restart docker

cd /opt/NullSpace
docker compose up --build -d

echo "Done. Checking traefik logs..."
docker compose logs traefik --tail 20
