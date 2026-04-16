---
name: voice_notes
description: Transcribe voice notes and execute the spoken instructions as if the user had typed them.
---

# Voice Note Handling

When the user sends a voice note or audio file, follow this procedure:

## Step 1 — Transcribe

Use whisper to transcribe the audio. Try English first, then German if English fails or produces gibberish:

```bash
whisper ~/.openclaw/workspace/media/inbound/<filename> --model base --language en --output_format txt
```

If the result looks wrong (garbled, wrong language, very low confidence), retry with German:

```bash
whisper ~/.openclaw/workspace/media/inbound/<filename> --model base --language de --output_format txt
```

## Step 2 — Execute

Treat the transcribed text as if the user typed it directly. Do whatever the message says — answer questions, run commands, search the web, write code, etc.

## Step 3 — Respond

Reply with:
1. A brief quote of what was said (so the user can confirm you heard correctly).
2. The result of carrying out the instruction.

Example response format:

> You said: "What's the weather in Berlin?"
>
> [weather result here]

## Notes
- Do not ask the user to confirm the transcription before acting — just do it.
- If the transcription is unclear or ambiguous, make your best guess and mention the uncertainty.
- The audio file path is always under `~/.openclaw/workspace/media/inbound/`.
