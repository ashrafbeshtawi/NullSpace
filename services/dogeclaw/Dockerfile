FROM node:22-bookworm-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    ca-certificates \
    curl \
    python3 \
    python3-pip \
    ffmpeg \
  && pip3 install --break-system-packages openai-whisper \
  && rm -rf /var/lib/apt/lists/*

# Pre-download whisper base model
RUN python3 -c "import whisper; whisper.load_model('base')"

# Install agent dependencies into a sibling directory so a host volume
# mount on /opt/agent (used in prod for hot code reloads) does not shadow
# the baked node_modules. The entrypoint symlinks them in at startup.
COPY agent/package*.json /opt/agent-deps/
RUN cd /opt/agent-deps && npm ci --omit=dev

# Bake the agent source as a fallback (used when no volume mount overrides /opt/agent)
COPY agent/ /opt/agent/

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
