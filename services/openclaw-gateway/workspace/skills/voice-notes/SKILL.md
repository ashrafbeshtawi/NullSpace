---
name: voice_notes
description: Transcribe voice notes and execute the spoken instructions as if the user had typed them.
---

# Voice Note Handling

When the user sends a voice note or audio file, you will see a media attachment in the message like:
`[media attached: /home/node/.openclaw/media/inbound/<uuid>.ogg (audio/ogg; codecs=opus)]`

**IMPORTANT: Do NOT delegate transcription to a subagent. Do it yourself directly.**

## Step 1 — Extract the file path

The exact file path is in the `[media attached: ...]` line. Use that full absolute path.

## Step 2 — Transcribe

Run whisper directly using the `exec` tool. Try English first:

```bash
whisper /home/node/.openclaw/media/inbound/<uuid>.ogg --model base --language en --output_format txt
```

If the result looks wrong (garbled, wrong language), retry with German:

```bash
whisper /home/node/.openclaw/media/inbound/<uuid>.ogg --model base --language de --output_format txt
```

Then read the output `.txt` file to get the transcription.

## Step 3 — Execute

Treat the transcribed text as if the user typed it directly. Do whatever the message says — answer questions, run commands, search the web, write code, etc.

## Step 4 — Respond

Reply with:
1. A brief quote of what was said (so the user can confirm you heard correctly).
2. The result of carrying out the instruction.

Example:

> You said: "What's the weather in Berlin?"
>
> [weather result here]

## Rules
- Do NOT ask the user to confirm the transcription — just do it.
- Do NOT delegate to a subagent — handle it yourself in the current session.
- Do NOT ask the user to resend the file — the path is already in the message.
- If the transcription is unclear, make your best guess and mention the uncertainty.
