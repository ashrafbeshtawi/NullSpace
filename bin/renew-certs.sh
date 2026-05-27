#!/bin/bash
# renew-certs.sh — kick traefik when Let's Encrypt renewal is stuck.
#
# Traefik renews ACME certs on its own, but occasionally gets wedged
# (rate-limit hit, DNS hiccup, expired account). This script:
#   1. Backs up acme.json (the cert store inside the letsencrypt volume).
#   2. Restarts traefik so it re-runs the ACME flow on next request.
#
# If you suspect the cert store itself is corrupt, delete the backup-and-
# restart path and instead remove acme.json so traefik regenerates from
# scratch — but mind the Let's Encrypt rate limits before doing that.

set -e

cd /opt/NullSpace

STAMP="$(date +%Y%m%dT%H%M%S)"

echo "==> backing up acme.json to acme.json.$STAMP.bak (inside letsencrypt volume)"
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec traefik \
  sh -c "cp /letsencrypt/acme.json /letsencrypt/acme.json.$STAMP.bak"

echo "==> restarting traefik"
docker compose -f docker-compose.yml -f docker-compose.prod.yml restart traefik

echo "==> tailing traefik logs (ctrl-c to exit)"
docker compose -f docker-compose.yml -f docker-compose.prod.yml logs -f --tail=50 traefik
