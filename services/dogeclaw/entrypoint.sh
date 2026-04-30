#!/bin/bash
set -e

# Ensure workspace directories exist
mkdir -p /root/agent-workspace/{files,sessions,queues,logs}

# Link baked node_modules into /opt/agent if the agent source was mounted
# from the host (which won't include node_modules).
if [ ! -e /opt/agent/node_modules ]; then
  ln -s /opt/agent-deps/node_modules /opt/agent/node_modules
fi

# If the host's package-lock.json drifts from what was baked into the image,
# refresh deps in /opt/agent-deps so the symlink keeps pointing at the right
# node_modules. Avoids needing an image rebuild for dependency-only changes.
HOST_LOCK=/opt/agent/package-lock.json
BAKED_LOCK=/opt/agent-deps/package-lock.json
if [ -f "$HOST_LOCK" ] && ! cmp -s "$HOST_LOCK" "$BAKED_LOCK"; then
  echo "[entrypoint] package-lock.json changed — reinstalling deps in /opt/agent-deps..."
  cp /opt/agent/package.json /opt/agent/package-lock.json /opt/agent-deps/
  cd /opt/agent-deps && npm ci --omit=dev
fi

echo "[entrypoint] Starting DogeClaw agent..."
exec node /opt/agent/src/index.js
