# NullSpace

Personal multi-service platform running on Docker with Traefik as a reverse proxy. Hosts a custom AI agent (DogeClaw), monitoring, error tracking, and management tools.

## Architecture

```
                              Internet
                                 │
                          ┌──────┴──────┐
                          │   Traefik   │  :80 → :443 (HTTPS + Let's Encrypt)
                          └──────┬──────┘
                                 │
   ┌─────────┬─────────┬─────────┼──────────┬─────────┬──────────┐
   │         │         │         │          │         │          │
┌──┴───┐  ┌──┴───┐  ┌──┴────┐ ┌─┴────┐ ┌───┴────┐ ┌──┴─────┐ ┌──┴──────┐
│Admin │  │Main  │  │DogeClaw│ │Status│ │Errors  │ │Traefik │ │Portainer│
│Panel │  │Site  │  │(agent) │ │(Kuma)│ │(Glitch)│ │Dashbrd │ │         │
└──────┘  └──────┘  └───┬────┘ └──────┘ └────────┘ └────────┘ └─────────┘
                        │
                        ▼  (Docker DNS)
                  ┌─────────────────────────────────────┐
                  │  Internal network                    │
                  │  ┌──────────┐ ┌───────┐ ┌────────┐  │
                  │  │PostgreSQL│ │ Redis │ │ Ollama │  │
                  │  └──────────┘ └───────┘ └────────┘  │
                  │                          (on-demand) │
                  └─────────────────────────────────────┘
```

## Services

### Apps
| Service | URL | Description |
|---------|-----|-------------|
| Main Site | `beshtawi.online` | Landing page |
| DogeClaw | `dogeclaw.beshtawi.online` | AI agent (web UI + Telegram + cron + tools) |
| Ollama | (internal only) | Local LLM server — manual start |

### Infrastructure
| Service | URL | Description |
|---------|-----|-------------|
| Admin Panel | `admin.beshtawi.online` | Dashboard linking all services (basic auth) |
| Traefik | `traefik.beshtawi.online` | Reverse proxy dashboard (basic auth) |
| Portainer | `portainer.beshtawi.online` | Docker management UI |

### Monitoring
| Service | URL | Description |
|---------|-----|-------------|
| Uptime Kuma | `status.beshtawi.online` | Uptime monitoring & status page |
| GlitchTip | `errors.beshtawi.online` | Sentry-compatible error tracking |

### Shared (internal)
| Service | Description |
|---------|-------------|
| PostgreSQL 16 | Shared database |
| Redis 7 | Shared cache/queue |

## DogeClaw Agent

A custom Node.js AI agent with a web UI and Telegram integration. Multi-agent, multi-model, tool-driven.

### Features
- **Web UI**: streaming chat with live thinking display, collapsible tool calls, session management, image/audio upload, agent picker
- **Telegram bots**: multiple bots configurable from the UI, immediate or periodic response modes, voice note transcription, image forwarding
- **Multi-agent**: define agents with custom system prompts, models, and skill assignments
- **Multi-model providers**: Ollama (local), OpenRouter, Google Gemini — configurable per agent
- **Skills system**: reusable knowledge/instructions stored in DB, assignable per-agent or public
- **Built-in tools**: shell exec, file ops, cron jobs, PostgreSQL queries, web search/fetch/research, MCP bridge, skill reading
- **Cron**: agent can schedule tasks for itself; results optionally pushed to Telegram
- **Audio**: Whisper transcription for voice messages (or forwarded raw to audio-capable models)
- **Vision**: image inputs forwarded to vision-capable models

### Admin UI

Visit `dogeclaw.beshtawi.online/admin` to manage:
- **Models** — add Ollama / OpenRouter / Google Gemini models, test the connection, set capabilities (text/image/audio/video)
- **Agents** — create agents with system prompts, assign a model, assign skills
- **Skills** — DB-backed skill definitions; assign to specific agents or leave public for all
- **Channels** — Telegram bots (token, allowed users, response mode, agent binding); webhooks auto-registered

All changes hot-reload — no restart needed.

### Database isolation

DogeClaw uses two PostgreSQL roles for safety:
- **Admin role** — used by the web UI for full CRUD on `models`, `agents`, `channels`, `skills`, `agent_skills`
- **Agent role** (`dogeclaw`) — used by the agent's `query_database` tool. Read-only on config tables, but can `CREATE TABLE` and full read/write on its own tables.

The `dogeclaw` role is created in `scripts/init-databases.sql`.

## Setup

### 1. Clone and configure

```bash
git clone <repo-url> /opt/NullSpace
cd /opt/NullSpace
cp .env.example .env
```

### 2. Generate basic auth password hash (for Traefik dashboard, Portainer, etc.)

```bash
# Install htpasswd if needed: apt install apache2-utils
echo $(htpasswd -nB admin) | sed -e s/\$/\$\$/g
```

Paste the output as `BASIC_AUTH_USERS` in `.env`.

### 3. Fill in `.env`

- `DB_USER`, `DB_PASSWORD`, `DB_NAME` — Postgres credentials
- `BASIC_AUTH_USERS` — the bcrypt hash from above
- `GLITCHTIP_SECRET_KEY` — `openssl rand -hex 32`
- `DOGECLAW_WEB_USER`, `DOGECLAW_WEB_PASSWORD` — DogeClaw login
- `DOGECLAW_WEB_SECRET` — `openssl rand -hex 32`
- `DOGECLAW_ADMIN_DATABASE_URL` — full-access connection string
- `DOGECLAW_DATABASE_URL` — restricted connection string (use `dogeclaw` user in prod)
- `DOGECLAW_TELEGRAM_MODE` — `polling` (dev) or `webhook` (prod)
- `DOGECLAW_WEBHOOK_URL` — `https://dogeclaw.beshtawi.online` for webhook mode

### 4. DNS

Set these A records:

| Type | Host | Value |
|------|------|-------|
| A | `@` | Server IP |
| A | `*` | Server IP |

### 5. Start

```bash
docker compose up --build -d
```

This starts everything except Ollama (which is on-demand).

### 6. Open

- Admin dashboard: `https://admin.beshtawi.online`
- DogeClaw: `https://dogeclaw.beshtawi.online` → log in → `/admin` to add models, agents, channels, skills

## Ollama (Local LLM)

Ollama runs in a separate container and is **not started by default** — it loads multi-GB model weights into RAM, so we only spin it up when needed. DogeClaw works fine without it using cloud models (Google Gemini, OpenRouter).

### Start Ollama

```bash
docker compose --profile manual up -d ollama
```

On first start it pulls the default model (`gemma4:e2b`, override with `OLLAMA_DEFAULT_MODEL` in `.env`). Subsequent starts are fast — model weights are cached in the `ollama_data` volume.

DogeClaw reaches it via Docker DNS at `http://ollama:11434`. In the admin UI, add an Ollama model with that base URL.

### Stop Ollama

```bash
docker compose stop ollama
```

Frees the RAM. Model weights stay in the volume.

### Pull additional models

```bash
docker exec ollama ollama pull qwen3:8b
docker exec ollama ollama pull llama3.1:8b
```

Then register them in DogeClaw admin (Models → New Model).

## Local Development

```bash
docker compose up --build
```

Add to `/etc/hosts`:
```
127.0.0.1  localhost
127.0.0.1  dogeclaw.localhost
127.0.0.1  admin.localhost
127.0.0.1  traefik.localhost
127.0.0.1  portainer.localhost
127.0.0.1  status.localhost
127.0.0.1  errors.localhost
```

Services run on `*.localhost:8000` (HTTP, no auth on Traefik dashboard).

## Adding a New Service

1. Create `services/my-app/` with a `Dockerfile`.
2. Add to `docker-compose.yml`:
   ```yaml
   my-app:
     build: ./services/my-app
     container_name: my-app
     labels:
       - "traefik.enable=true"
       - "traefik.http.services.my-app.loadbalancer.server.port=80"
     networks:
       - public-web
   ```
3. Add the host rule in `docker-compose.prod.yml` and `docker-compose.override.yml`.
4. If it needs a database, add the `CREATE DATABASE` to `scripts/init-databases.sql`.
5. Push to `main` — CI/CD deploys automatically.

## CI/CD

Pushes to `main` trigger a GitHub Actions workflow that SSHs into the VPS, pulls the latest code, and rebuilds the containers.

Required GitHub secrets:
- `VPS_HOST`
- `VPS_USER`
- `VPS_PASSWORD`

The deploy script runs:
```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml down --remove-orphans
docker compose -f docker-compose.yml -f docker-compose.prod.yml up --build -d
```

Ollama is not redeployed automatically — start it manually on the VPS when needed.

## Repo Layout

```
NullSpace/
├── docker-compose.yml              # Base compose (all services)
├── docker-compose.prod.yml         # Prod overrides (HTTPS, basic auth, real domains)
├── docker-compose.override.yml     # Dev overrides (HTTP, localhost domains)
├── .env / .env.example             # Secrets and config
├── scripts/
│   └── init-databases.sql          # Postgres initial setup (creates extra DBs + dogeclaw role)
└── services/
    ├── admin/                      # PHP dashboard linking all services
    ├── default/                    # Main landing page
    ├── ollama/                     # Ollama LLM container (manual start)
    │   ├── Dockerfile
    │   └── entrypoint.sh
    └── dogeclaw/                   # DogeClaw AI agent (auto-starts)
        ├── Dockerfile
        ├── entrypoint.sh
        ├── logo.png
        └── agent/
            ├── package.json
            └── src/
                ├── index.js        # Boot orchestrator
                ├── config.js
                ├── agent.js        # Core agent loop (LLM + tools)
                ├── llm.js          # Ollama / OpenRouter / Gemini drivers
                ├── audio.js        # Whisper transcription
                ├── db/
                │   ├── pool.js     # Two pg pools (admin + agent)
                │   └── schema.js   # Auto-migration
                ├── tools/
                │   ├── index.js    # ToolRegistry
                │   ├── exec.js     # run_command
                │   ├── files.js    # file_operation
                │   ├── cron.js     # manage_cron
                │   ├── db.js       # query_database
                │   ├── web.js      # web_search, web_fetch, web_research
                │   ├── skills.js   # read_skill
                │   └── mcp.js      # MCP tool bridge
                ├── cron/runner.js  # In-process cron scheduler
                ├── channels/
                │   └── telegram.js # Multi-bot Telegram (polling or webhook)
                ├── mcp/client.js   # MCP stdio clients
                └── web/
                    ├── server.js   # Express + SSE chat + REST API
                    └── public/
                        ├── login.html
                        ├── index.html  # Chat UI
                        ├── admin.html  # Admin panel
                        └── logo.png
```
