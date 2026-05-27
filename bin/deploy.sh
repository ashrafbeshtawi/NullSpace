#!/bin/bash
# deploy.sh — pull latest code + images and redeploy the prod stack.
#
# Runs from /opt/NullSpace on the VPS. Pulls newest commits, refreshes any
# remote images (e.g. ghcr.io/ashrafbeshtawi/kiwelt:latest, dogeclaw, etc.),
# and recreates only the containers whose image or config actually changed.
# --remove-orphans cleans up services that were removed from compose files.

set -e

cd /opt/NullSpace

echo "==> git pull"
git pull --ff-only

echo "==> docker compose pull"
docker compose -f docker-compose.yml -f docker-compose.prod.yml pull

echo "==> docker compose up -d"
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --remove-orphans

echo "==> running containers"
docker compose -f docker-compose.yml -f docker-compose.prod.yml ps
