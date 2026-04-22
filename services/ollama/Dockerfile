FROM ollama/ollama:latest

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    ca-certificates \
    curl \
    python3 \
    python3-pip \
    ffmpeg \
  && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
  && apt-get install -y --no-install-recommends nodejs \
  && pip3 install --break-system-packages openai-whisper \
  && rm -rf /var/lib/apt/lists/*

# Pre-download whisper base model
RUN python3 -c "import whisper; whisper.load_model('base')"

# Install DogeClaw agent
COPY agent/ /opt/agent/
RUN cd /opt/agent && npm ci --omit=dev

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
