---
name: voice_notes
description: Transcribe voice notes using the exec tool to run the whisper CLI, then execute the spoken instructions.
---

# Voice Note Handling

When the user sends a voice note or audio file, you will see a media attachment in the message like:
`[media attached: /home/node/.openclaw/media/inbound/<uuid>.ogg (audio/ogg; codecs=opus)]`

## How to transcribe

Whisper is a **command-line program** installed on this system. It is NOT an API tool.
You MUST use the `exec` tool to run it as a shell command. Do NOT try to call any API or tool named "whisper" or "openai_whisper".

### Step 1 — Extract the file path

The exact file path is in the `[media attached: ...]` line. Copy the full absolute path.

### Step 2 — Run whisper via exec

Use the `exec` tool to run this shell command (replace the path with the actual one):

```
exec: whisper /home/node/.openclaw/media/inbound/<uuid>.ogg --model base --language en --output_format txt
```

If the output looks wrong (garbled text, wrong language), retry with German:

```
exec: whisper /home/node/.openclaw/media/inbound/<uuid>.ogg --model base --language de --output_format txt
```

Whisper prints the transcription to stdout. Use that output directly.

### Step 3 — Execute the instruction

Treat the transcribed text as if the user typed it. Do whatever the message says — answer questions, run commands, search the web, write code, etc.

### Step 4 — Respond

Reply with:
1. A brief quote of what was said (so the user can confirm you heard correctly).
2. The result of carrying out the instruction.

Example:

> You said: "What's the weather in Berlin?"
>
> [weather result here]

## Rules
- Use the `exec` tool to run `whisper` as a shell command. There is NO whisper API tool.
- Do NOT ask the user to confirm the transcription — just do it.
- Do NOT delegate to a subagent — handle it yourself in the current session.
- Do NOT ask the user to resend the file — the path is already in the message.
- Do NOT say you cannot process audio — you CAN, using the exec tool.
- If the transcription is unclear, make your best guess and mention the uncertainty.
