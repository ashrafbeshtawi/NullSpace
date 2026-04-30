# DogeClaw

Custom Node.js AI agent. Web UI + Telegram + cron + tools, multi-agent and multi-model.

For the high-level overview (features, admin UI, setup), see the [root README](../../README.md). This file documents the layout of *this service* and how to work on it.

## Layout

```
services/dogeclaw/
├── Dockerfile
├── entrypoint.sh
├── logo.png
├── bin/                # Helper scripts (run from anywhere)
└── agent/              # Mounted into the container at /opt/agent
    ├── package.json
    ├── package-lock.json
    ├── node_modules/   # Installed by entrypoint on every start (gitignored)
    └── src/            # See root README for full src tree
```

## How code and deps reach the container

The image is mostly OS + Node + Whisper. The agent source is bind-mounted from the host, and dependencies are installed at runtime — not at image build time.

| Path in container | Source | Updated by |
|---|---|---|
| `/opt/agent/` | host bind mount: `./services/dogeclaw/agent` | `git pull` (prod) or your editor (dev) |
| `/opt/agent/node_modules/` | `npm install` runs on every container start | container restart |
| `/root/agent-workspace/` | named docker volume `agent_workspace` | the agent itself (sessions, files, queues, logs) |

The entrypoint runs `npm install --omit=dev && npm rebuild` before starting the agent. This is:
- **Idempotent** — fast (~1–3s) when nothing changed.
- **Self-healing** — picks up new deps from a changed `package.json` automatically on restart.
- **Cross-platform safe** — `npm rebuild` recompiles native modules for Linux even if you accidentally ran `npm install` on your Mac.

`node_modules` lives inside the host bind mount, so your IDE on the Mac sees it too (autocomplete, jump-to-def). It's gitignored.

## Helper scripts (`bin/`)

Run from any directory in the repo:

```bash
services/dogeclaw/bin/build      # docker compose build dogeclaw  (only needed when Dockerfile changes)
services/dogeclaw/bin/restart    # docker compose restart dogeclaw  (picks up source + dep changes)
services/dogeclaw/bin/install    # exec into running container, reinstall deps without restarting
services/dogeclaw/bin/logs       # tail container logs
services/dogeclaw/bin/shell      # open bash inside the running container
```

## Hot reload

The agent is started with `node --watch`, so editing any `.js` file (or any file imported by Node) **automatically restarts the process inside the same container**. No manual restart needed for code changes.

Caveats:
- Resets in-memory state (cron schedules re-register from DB; sessions are file-backed so they survive).
- Static files served by Express (`web/public/*.html`) are re-read from disk per request — no restart needed for HTML/CSS edits.
- Adding a new dependency still needs `bin/install` (or container restart) so npm fetches the package — `node --watch` only restarts; it doesn't run npm.

## Typical workflows

**Edit JS:**
```bash
# edit something under agent/src/
# → node --watch auto-restarts the process
```

**Edit HTML/CSS:**
```bash
# edit agent/src/web/public/*.html
# → next browser refresh sees the new file (Express reads from disk)
```

**Add a dependency:**
```bash
cd services/dogeclaw/agent
npm install <pkg>
services/dogeclaw/bin/install   # makes the package available in the container
# → node --watch auto-restarts when you import it in your code
```

**Change the Dockerfile (system deps, base image, whisper model, etc.):**
```bash
services/dogeclaw/bin/build
services/dogeclaw/bin/restart
```

## CI/CD behavior for this service

Triggered on push to `main`:

- Image **rebuilds only** when `services/dogeclaw/Dockerfile` changes.
- Source and dependency changes don't need a rebuild — the deploy job restarts the container, the bind mount exposes the new source, and the entrypoint installs the new deps.
- Build runs *before* anything is taken down — a failed build leaves prod running on the previous image.

See `.github/workflows/deploy.yml` for the full pipeline.

## Configuration

All runtime config is read from environment variables — see the `dogeclaw` service block in `docker-compose.yml` and the documented variables in `.env.example`.
