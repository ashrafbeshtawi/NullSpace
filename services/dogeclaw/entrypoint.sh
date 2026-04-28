#!/bin/bash

# Ensure workspace directories exist
mkdir -p /root/agent-workspace/{files,sessions,queues,logs}

echo "[entrypoint] Starting DogeClaw agent..."
exec node /opt/agent/src/index.js
