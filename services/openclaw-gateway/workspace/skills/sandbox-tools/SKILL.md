---
name: sandbox_tools
description: Available tools and runtimes in the sandbox execution environment.
---

# Sandbox Environment

All commands run inside a Docker sandbox container based on Debian Bookworm.
The following tools are pre-installed — use them directly without installing anything.

## Shell & System
- `bash`, `sh` — shell access
- `curl`, `wget` — HTTP requests
- `git` — version control
- `jq` — JSON processing
- `file`, `unzip`, `less` — file utilities
- `procps` (`ps`, `top`) — process management

## Python
- **Use `python3`** (not `python`)
- `pip3` — package manager

## Node.js
- `nodejs`, `npm` — JavaScript runtime

## Audio & Video
- `ffmpeg` — audio/video conversion, extraction, transcoding
- `whisper` — OpenAI Whisper speech-to-text transcription
  - Usage: `whisper audio.mp3 --model base`
  - Supports: mp3, wav, m4a, flac, ogg, webm

## Documents
- `pdftotext`, `pdfinfo` (poppler-utils) — PDF text extraction

## Important Notes
- The workspace at `/workspace` is writable — use it for temporary files.
- The sandbox has **no network access** by default.
- Do not attempt to install packages with `apt` or `sudo` — everything you need is pre-installed.
