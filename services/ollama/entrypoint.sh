#!/bin/bash

# Start Ollama server in the background
ollama serve &

# Wait for the server to be ready
until ollama list >/dev/null 2>&1; do
  sleep 1
done

# Pull the model if not already present
if ! ollama list | grep -q 'gemma4:e2b'; then
  echo "Pulling gemma4:e2b..."
  ollama pull gemma4:e2b || echo "Warning: failed to pull gemma4:e2b"
fi

# Ensure workspace directories exist
mkdir -p /root/agent-workspace/{files,sessions,queues,logs}

# Start DogeClaw agent
echo "[entrypoint] Starting DogeClaw agent..."
node /opt/agent/src/index.js >> /root/agent-workspace/logs/agent.log 2>&1 &

# Keep Ollama as the main process
wait
