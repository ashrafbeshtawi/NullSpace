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

All public hostnames derive from `${DOMAIN}` in `.env` (default `datenflow.de`). Routing is **subdomain-only**: the apex 301-redirects to `www.${DOMAIN}`.

### Apps
| Service | URL | Description |
|---------|-----|-------------|
| Main Site | `www.${DOMAIN}` | Landing page (apex redirects here) |
| DogeClaw | `dogeclaw.${DOMAIN}` | AI agent (web UI + Telegram + cron + tools) |
| Ollama | (internal only) | Local LLM server — manual start |

### Infrastructure
| Service | URL | Description |
|---------|-----|-------------|
| Admin Panel | `admin.${DOMAIN}` | Dashboard linking all services (basic auth) |
| Traefik | `traefik.${DOMAIN}` | Reverse proxy dashboard (basic auth) |
| Portainer | `portainer.${DOMAIN}` | Docker management UI |

### Monitoring
| Service | URL | Description |
|---------|-----|-------------|
| Uptime Kuma | `status.${DOMAIN}` | Uptime monitoring & status page |
| GlitchTip | `errors.${DOMAIN}` | Sentry-compatible error tracking |

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

Visit `dogeclaw.${DOMAIN}/admin` to manage **Models**, **Agents**, **Skills**, and **Channels** (Telegram bots; webhooks auto-registered). All changes hot-reload — no restart.

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

- `DOMAIN` — public domain; everything else (Host rules, GlitchTip URL, DogeClaw webhook, from-email) is derived from it
- `DB_USER`, `DB_PASSWORD`, `DB_NAME` — Postgres credentials
- `BASIC_AUTH_USERS` — the bcrypt hash from above
- `GLITCHTIP_SECRET_KEY` — `openssl rand -hex 32`
- `DOGECLAW_WEB_USER`, `DOGECLAW_WEB_PASSWORD` — DogeClaw login
- `DOGECLAW_WEB_SECRET` — `openssl rand -hex 32`
- `DOGECLAW_ADMIN_DATABASE_URL` — full-access connection string
- `DOGECLAW_DATABASE_URL` — restricted connection string (use `dogeclaw` user in prod)
- `DOGECLAW_TELEGRAM_MODE` — `polling` (dev) or `webhook` (prod)

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

- Admin dashboard: `https://admin.${DOMAIN}`
- DogeClaw: `https://dogeclaw.${DOMAIN}` → log in → `/admin` to add models, agents, channels, skills

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

## Backups

Two-layer strategy: nightly local dumps + encrypted off-site copies to Backblaze B2.

### What gets backed up

- **PostgreSQL** — every database (`nullspace`, `glitchtip`, `dogeclaw`, …) via `pg_dumpall`
- **`/opt/NullSpace/.env`** — host-only secrets not in the repo
- **Named volumes**: `agent_workspace`, `uptime_kuma_data`, `letsencrypt`

Explicitly **not** backed up — recoverable by other means:
- `postgres_data` (covered by the pg dump above)
- `ollama_data` (re-pull models via `ollama pull`)
- `redis_data` (cache)
- `portainer_data` (reconfigure in minutes)
- Source code / container images (already on GitHub / GHCR)

### Layer 1 — local dumps

`bin/backup-postgres.sh` runs `pg_dumpall` against the running postgres container and writes `/var/backups/nullspace/pg-YYYY-MM-DD.sql.gz`. Retention: 14 days local (`find -mtime +14 -delete`).

### Layer 2 — off-site to Backblaze B2 (via restic)

`bin/backup-offsite.sh` calls the local script, tars the named volumes into a staging dir, and pushes everything to a [restic](https://restic.net/) repository on B2:

- **Client-side encryption** with a key you control (`RESTIC_PASSWORD`). B2 only ever sees opaque ciphertext.
- **Block-level deduplication** — day-2+ uploads are deltas, not full re-uploads.
- **Snapshot retention** via `restic forget --keep-daily 14 --keep-weekly 4 --keep-monthly 6 --prune`.

Credentials live in `/etc/nullspace-backup.env` (root, mode 600), separate from the project `.env` so they never leak into container env:

```
B2_ACCOUNT_ID=<keyID>
B2_ACCOUNT_KEY=<applicationKey>
RESTIC_REPOSITORY=b2:<bucket>
RESTIC_PASSWORD=<encryption key — store in password manager>
BACKUP_HEARTBEAT_URL=<optional Uptime Kuma push URL>
```

> ⚠ Lose `RESTIC_PASSWORD` and the backups are permanently unreadable — B2 cannot help. Keep a copy in a password manager off the VPS.

### One-time setup on a fresh VPS

```bash
apt install -y restic
sudo tee /etc/nullspace-backup.env >/dev/null <<EOF
B2_ACCOUNT_ID="..."
B2_ACCOUNT_KEY="..."
RESTIC_REPOSITORY="b2:<bucket>"
RESTIC_PASSWORD="<long-random>"
EOF
sudo chmod 600 /etc/nullspace-backup.env

set -a; . /etc/nullspace-backup.env; set +a
restic init

# Schedule the daily run as root
sudo crontab -e
# add: 0 3 * * * /opt/NullSpace/bin/backup-offsite.sh >> /var/log/nullspace-backup.log 2>&1
```

### Restoring

`bin/restore-offsite.sh` covers the common paths:

```bash
restore-offsite.sh list                # list snapshots
restore-offsite.sh check               # verify repo integrity
restore-offsite.sh env  <snap>         # restore .env only
restore-offsite.sh pg   <snap>         # replay latest pg dump from snapshot
restore-offsite.sh full <snap>         # stop stack → restore everything → bring back up
```

`<snap>` is a restic snapshot id or the literal `latest`. Destructive commands prompt before proceeding; set `NULLSPACE_RESTORE_YES=1` to skip prompts for unattended use.

### Operational hygiene

- **Quarterly restore drill** — restore `latest` to a scratch directory and `gunzip -t` the dump. A backup you've never restored is hope, not insurance.
- **Heartbeat monitoring** — point an Uptime Kuma "Push" monitor at the `BACKUP_HEARTBEAT_URL`. If the daily run fails or the VPS is down, you find out within the hour instead of months later.
- **Log rotation** — `/etc/logrotate.d/nullspace` rotates `/var/log/nullspace-*.log` weekly.

## Repo Layout

```
NullSpace/
├── docker-compose.yml              # Base compose (all services)
├── docker-compose.prod.yml         # Prod overrides (HTTPS, basic auth, real domains)
├── docker-compose.override.yml     # Dev overrides (HTTP, localhost domains, mailpit)
├── .env / .env.example             # Secrets and config
├── bin/                            # VPS maintenance scripts (cron + admin panel buttons)
│   ├── deploy.sh                   # git pull + docker compose pull + up -d
│   ├── backup-postgres.sh          # daily pg_dumpall → /var/backups/nullspace
│   ├── backup-offsite.sh           # daily push to restic repo on B2
│   ├── restore-postgres.sh         # replay a local pg dump
│   ├── restore-offsite.sh          # list / check / restore from the B2 repo
│   ├── cleanup.sh                  # docker image + builder prune
│   ├── renew-certs.sh              # nudge traefik to retry Let's Encrypt
│   ├── shell.sh                    # docker compose exec wrapper
│   └── fix-docker-api.sh           # one-shot Docker 26+ min-API-version workaround
├── scripts/
│   └── cluster-init.sql            # Idempotent CREATE DATABASE statements run by postgres-init
└── services/
    ├── admin/                      # PHP dashboard + ops buttons (deploy / backup / etc.)
    └── ollama/                     # Ollama LLM container (manual start)
```

DogeClaw lives in its own repo — see [github.com/ashrafbeshtawi/dogeclaw](https://github.com/ashrafbeshtawi/dogeclaw).
