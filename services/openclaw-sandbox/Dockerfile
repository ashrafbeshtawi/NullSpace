FROM debian:bookworm-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
    # Core tools
    curl wget git jq ca-certificates \
    # Python
    python3 python3-pip python3-venv \
    # Node.js (via NodeSource)
    nodejs npm \
    # Audio/video
    ffmpeg \
    # Text/document processing
    poppler-utils \
    # Shell utilities
    procps less file unzip \
    && rm -rf /var/lib/apt/lists/*

# Install openai-whisper for speech-to-text
RUN pip3 install --break-system-packages openai-whisper

# Create a non-root user matching OpenClaw's expectations
RUN useradd -m -s /bin/bash -u 1000 sandbox
USER sandbox
WORKDIR /workspace
