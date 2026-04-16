# TOOLS.md - Environment & Available Tools

## Sandbox Environment

All commands run inside a Docker sandbox container (`openclaw-sandbox:custom`) based on Debian Bookworm.
You have full access to the following tools — use them directly without installing anything.

### Shell & System
- `bash`, `sh` — shell access
- `curl`, `wget` — HTTP requests
- `git` — version control
- `jq` — JSON processing
- `file`, `unzip`, `less` — file utilities
- `procps` (`ps`, `top`) — process management

### Python
- `python3`, `pip3` — Python 3 runtime
- Use `python3` (not `python`)

### Node.js
- `nodejs`, `npm` — JavaScript runtime

### Audio & Video
- `ffmpeg` — audio/video conversion, extraction, transcoding
- `whisper` — OpenAI Whisper speech-to-text transcription
  - Usage: `whisper audio.mp3 --model base`
  - Models download on first use (needs network)
  - Supports: mp3, wav, m4a, flac, ogg, webm

### Documents
- `pdftotext`, `pdfinfo` (poppler-utils) — PDF text extraction

## Network

The sandbox has no network access by default. If a task requires downloading
something (e.g. whisper models, pip packages), mention this limitation.

## Notes
- The sandbox is ephemeral — files written here do not persist across sessions
- You run as user `sandbox` (uid 1000), non-root
- The workspace is at `/workspace`
