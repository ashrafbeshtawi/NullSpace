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

**Source lives in its own repo:** [github.com/ashrafbeshtawi/dogeclaw](https://github.com/ashrafbeshtawi/dogeclaw). NullSpace consumes it as two prebuilt images from GHCR:
- `ghcr.io/ashrafbeshtawi/dogeclaw` — the agent
- `ghcr.io/ashrafbeshtawi/dogeclaw-migrations` — Flyway with the agent's schema baked in

Both pinned to the `1.0` tag in `docker-compose.yml`. Bump that pin to roll a new dogeclaw version into NullSpace.

### Features
- Web UI with streaming chat, live thinking display, collapsible tool calls, image/audio upload
- Multi-bot Telegram (immediate or periodic response modes, voice transcription, image forwarding)
- Multi-agent + multi-model (Ollama, OpenRouter, Google Gemini), DB-backed skills system
- Built-in tools: shell exec, file ops, cron, PostgreSQL queries, web search/fetch/research, MCP bridge

### Admin UI

Visit `dogeclaw.beshtawi.online/admin` to manage **Models**, **Agents**, **Skills**, and **Channels** (Telegram bots; webhooks auto-registered). All changes hot-reload — no restart.

### Database isolation

DogeClaw uses two PostgreSQL roles:
- **Admin role** — full CRUD on dogeclaw config tables
- **Agent role** (`dogeclaw`) — restricted SELECT on config tables; can manage its own working tables

Both the role and the schema are created by the `dogeclaw-migrations` image when the stack first starts — NullSpace doesn't manage any of that itself anymore.

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

Pushes to `main` trigger a GitHub Actions workflow that SSHs into the VPS, pulls the latest code, refreshes registry images, and recreates the containers.

Required GitHub secrets:
- `VPS_HOST`
- `VPS_USER`
- `VPS_PASSWORD`

The deploy script runs:
```bash
git pull origin main
docker compose ... pull        # refreshes dogeclaw + other registry images
docker compose ... down --remove-orphans
docker compose ... up -d --build   # builds local services (admin, default, ollama)
```

DogeClaw upgrades happen by bumping the image tag in `docker-compose.yml` (e.g. `:1.0` → `:1.1`) and pushing — the `pull` step grabs the new image. Rolling within the same major.minor (`1.0.x` patches) needs no NullSpace push at all; the next deploy here picks them up via `pull`.

Ollama is not redeployed automatically — start it manually on the VPS when needed.

## Repo Layout

```
NullSpace/
├── docker-compose.yml              # Base compose (all services)
├── docker-compose.prod.yml         # Prod overrides (HTTPS, basic auth, real domains)
├── docker-compose.override.yml     # Dev overrides (HTTP, localhost domains)
├── .env / .env.example             # Secrets and config
├── scripts/
│   └── init-databases.sql          # Postgres initial setup (creates extra DBs)
└── services/
    ├── admin/                      # PHP dashboard linking all services
    ├── default/                    # Main landing page
    ├── ollama/                     # Ollama LLM container (manual start)
    │   ├── Dockerfile
    │   └── entrypoint.sh
    └── migrations/sql/             # Platform-level Flyway migrations (empty for now)
```

DogeClaw lives in its own repo — see [github.com/ashrafbeshtawi/dogeclaw](https://github.com/ashrafbeshtawi/dogeclaw).
