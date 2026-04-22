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

# Keep Ollama as the main process
wait
