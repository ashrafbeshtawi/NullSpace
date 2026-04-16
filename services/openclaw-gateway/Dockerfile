FROM ghcr.io/openclaw/openclaw:latest

USER root
RUN apt-get update && apt-get install -y --no-install-recommends \
    # Python
    python3 python3-pip python3-venv \
    # Audio/video
    ffmpeg \
    # Text/document processing
    poppler-utils \
    # Shell utilities
    curl wget git jq procps less file unzip ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Install openai-whisper and pre-download the base model
RUN pip3 install --break-system-packages openai-whisper
RUN python3 -c "import whisper; whisper.load_model('base')"

USER node
