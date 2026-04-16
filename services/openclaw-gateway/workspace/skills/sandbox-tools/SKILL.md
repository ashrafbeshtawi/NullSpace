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
  - Usage: `whisper <file> --model base --language <lang>`
  - The `base` model is pre-downloaded and ready to use.
  - Supports: mp3, wav, m4a, flac, ogg, opus, webm

## Documents
- `pdftotext`, `pdfinfo` (poppler-utils) — PDF text extraction

## Media Files
- Inbound media (audio, images, files sent by the user) is stored at:
  `~/.openclaw/workspace/media/inbound/`
- When transcribing audio, use the full path to the file in that directory.

## Important Notes
- Use `python3` not `python`.
- Do not attempt to install packages with `apt` or `sudo` — everything you need is pre-installed.
- Commands run directly on the gateway.
