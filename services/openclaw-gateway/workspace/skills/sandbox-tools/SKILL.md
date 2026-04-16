---
name: sandbox_tools
description: Available tools and runtimes in the execution environment.
---

# Available Tools

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

## Audio & Video
- `ffmpeg` — audio/video conversion, extraction, transcoding
- `whisper` — OpenAI Whisper speech-to-text transcription
  - Usage: `whisper audio.mp3 --model base`
  - Supports: mp3, wav, m4a, flac, ogg, webm

## Documents
- `pdftotext`, `pdfinfo` (poppler-utils) — PDF text extraction

## Important Notes
- Use `python3` not `python`.
- Do not attempt to install packages with `apt` or `sudo` — everything you need is pre-installed.
- Commands run directly on the gateway host.
