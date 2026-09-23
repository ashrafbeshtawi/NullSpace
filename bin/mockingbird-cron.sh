#!/bin/bash
# mockingbird-cron.sh — fire one of Mockingbird's cron webhooks.
#
# Mockingbird has no internal scheduler: scheduled posts go out only when
# something POSTs /api/webhooks/publish, and orphaned media is only pruned
# by /api/webhooks/cleanup-media. Both authenticate via the x-webhook-secret
# header (MOCKINGBIRD_WEBHOOK_SECRET in .env).
#
# Usage: mockingbird-cron.sh publish|cleanup-media
#
# Root crontab:
#   * * * * *  /opt/NullSpace/bin/mockingbird-cron.sh publish       >> /var/log/nullspace-mockingbird-cron.log 2>&1
#   15 4 * * * /opt/NullSpace/bin/mockingbird-cron.sh cleanup-media >> /var/log/nullspace-mockingbird-cron.log 2>&1

set -e

HOOK="${1:?usage: $0 publish|cleanup-media}"

cd /opt/NullSpace

# Load MOCKINGBIRD_WEBHOOK_SECRET + DOMAIN from the project .env.
set -a
. ./.env
set +a

curl -fsS -X POST -H "x-webhook-secret: $MOCKINGBIRD_WEBHOOK_SECRET" \
    "https://mockingbird.${DOMAIN}/api/webhooks/${HOOK}"
