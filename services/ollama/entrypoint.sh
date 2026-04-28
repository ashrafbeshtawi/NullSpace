#!/bin/bash

# Bind to all interfaces so dogeclaw can reach us
export OLLAMA_HOST=0.0.0.0:11434

# Start Ollama server in the background
ollama serve &

# Wait for the server to be ready
until ollama list >/dev/null 2>&1; do
  sleep 1
done

# Pull the default model if not present
DEFAULT_MODEL="${OLLAMA_DEFAULT_MODEL:-gemma4:e2b}"
if ! ollama list | grep -q "$DEFAULT_MODEL"; then
  echo "[ollama] Pulling $DEFAULT_MODEL..."
  ollama pull "$DEFAULT_MODEL" || echo "[ollama] Warning: failed to pull $DEFAULT_MODEL"
fi

echo "[ollama] Ready"

# Keep Ollama running
wait
