# NullSpace

Personal multi-service platform running on Docker with Traefik as a reverse proxy.

## Architecture

```
                         Internet
                            |
                     ┌──────┴──────┐
                     │   Traefik   │  :80 (→ HTTPS redirect)
                     │   (proxy)   │  :443 (HTTPS + Let's Encrypt)
                     └──────┬──────┘
                            │
        ┌───────────┬───────┼───────────┬───────────┐
        │           │       │           │           │
   ┌────┴───┐ ┌─────┴──┐ ┌─┴──┐ ┌──────┴──┐ ┌─────┴────┐
   │ Admin  │ │Portainer│ │Apps│ │GlitchTip│ │ OpenClaw │
   │ Panel  │ │        │ │    │ │(errors) │ │  (chat)  │
   └────────┘ └────────┘ └──┬─┘ └────┬────┘ └──────────┘
                             │       │
                        ┌────┴───────┴────────────────┐
                        │     Internal Network         │
                        │  ┌──────────┐ ┌───────┐     │
                        │  │PostgreSQL│ │ Redis │     │
                        │  └──────────┘ └───────┘     │
                        └─────────────────────────────┘
```

## Services

### Apps
| Service | URL | Description |
|---------|-----|-------------|
| Main Site | `beshtawi.online` | Landing page |
| OpenClaw | `chat.beshtawi.online` | AI assistant (OpenRouter LLM, basic auth) |

### Infrastructure
| Service | URL | Description |
|---------|-----|-------------|
| Traefik | `traefik.beshtawi.online` | Reverse proxy dashboard (basic auth) |
| Portainer | `portainer.beshtawi.online` | Docker management UI |
| Admin Panel | `admin.beshtawi.online` | Links to all services (basic auth) |

### Monitoring
| Service | URL | Description |
|---------|-----|-------------|
| Uptime Kuma | `status.beshtawi.online` | Uptime monitoring & status page |
| GlitchTip | `errors.beshtawi.online` | Error tracking (Sentry-compatible) |

### Shared
| Service | Description |
|---------|-------------|
| PostgreSQL 16 | Shared database (internal only) |
| Redis 7 | Shared cache/queue (internal only) |

## Setup

### 1. Clone and configure

```bash
git clone <repo-url> /opt/NullSpace
cd /opt/NullSpace
cp .env.example .env
```

### 2. Generate basic auth password hash

```bash
# Install htpasswd if needed: apt install apache2-utils
echo $(htpasswd -nB admin) | sed -e s/\$/\$\$/g
```

Paste the output as `ADMIN_PASSWORD_HASH` in `.env`.

### 3. Update .env

Fill in all values in `.env` — database credentials, GlitchTip secret key, the password hash, and OpenClaw keys (see below).

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

## OpenClaw Setup

OpenClaw runs as a containerized AI assistant. The compose stack stays minimal — onboarding is a **one-time manual step** per environment, matching [the upstream docs' flow](https://docs.openclaw.ai/install/docker):

| Service | Role |
|---------|------|
| `openclaw-init` | One-shot Alpine container — chowns the named volume to uid 1000 |
| `openclaw-gateway` | Long-running gateway exposed via Traefik |
| `openclaw-cli` | Profile-gated sidecar for post-start CLI commands |

### Prerequisites

Set these in `.env`:

| Env var | Purpose |
|---------|---------|
| `OPENROUTER_API_KEY` | OpenRouter API key |
| `OPENCLAW_GATEWAY_TOKEN` | Auth token browsers present to connect. Stored as a SecretRef in `openclaw.json`, resolved from this env var at runtime |

### First-time setup (per environment)

Run this **once** on a fresh volume. The wizard is interactive — pick OpenRouter as the provider, token auth, `lan` bind mode, and let it reference `OPENCLAW_GATEWAY_TOKEN` as the token env var.

Every command uses explicit `-f` flags so the right environment config is loaded. Commands below are for **prod**; for dev, swap `docker-compose.prod.yml` → `docker-compose.override.yml`.

```bash
# 1. Bring up init to chown the volume
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d openclaw-init

# 2. Run the interactive onboarding wizard
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
  run --rm --no-deps --entrypoint openclaw openclaw-gateway onboard

# 3. Set the Control UI allowed origins
#    Prod:  '["https://chat.beshtawi.online"]'
#    Dev:   '["http://localhost:18789","http://127.0.0.1:18789","http://chat.localhost:8000"]'
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
  run --rm --no-deps --entrypoint openclaw openclaw-gateway \
  config set gateway.controlUi.allowedOrigins \
  '["https://chat.beshtawi.online"]' --strict-json

# 4. Trust Traefik's proxy headers (RFC 1918 private ranges)
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
  run --rm --no-deps --entrypoint openclaw openclaw-gateway \
  config set gateway.trustedProxies \
  '["10.0.0.0/8","172.16.0.0/12","192.168.0.0/16"]' --strict-json

# 5. Start the gateway
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d openclaw-gateway
```

### Pairing a browser

Each new browser must be paired with the gateway:

1. Open the Control UI (`https://chat.beshtawi.online` or `http://chat.localhost:8000`).
2. Paste the `OPENCLAW_GATEWAY_TOKEN` from `.env` and click Connect.
3. The UI will show "pairing required". On the server, approve it:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml run --rm openclaw-cli devices list
docker compose -f docker-compose.yml -f docker-compose.prod.yml run --rm openclaw-cli devices approve <id>
```

### Managing devices and config

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml run --rm openclaw-cli devices list
docker compose -f docker-compose.yml -f docker-compose.prod.yml run --rm openclaw-cli devices remove
docker compose -f docker-compose.yml -f docker-compose.prod.yml run --rm openclaw-cli config get <path>
docker compose -f docker-compose.yml -f docker-compose.prod.yml run --rm openclaw-cli config set <path> <json-value>
```

### Starting over from scratch

To blow away all OpenClaw state and redo onboarding:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml rm -sf openclaw-init openclaw-gateway openclaw-cli
docker volume rm nullspace_openclaw_config
# Then re-run the First-time setup steps above.
```

### Agent sandbox

The gateway runs with `OPENCLAW_SANDBOX=1` and the Docker socket mounted. When the agent needs to run shell commands, write files, or execute code, it spawns an isolated Docker container (the "sandbox") instead of running in the gateway itself.

A custom sandbox image (`openclaw-sandbox:custom`) is built from `services/openclaw-sandbox/Dockerfile`. It includes `ffmpeg`, `python3`, `whisper` (speech-to-text), `git`, `curl`, `jq`, and `nodejs`.

**Build the sandbox image** (once per host, and after any Dockerfile change):

```bash
docker compose build openclaw-sandbox
```

**Configure during onboarding** — when the wizard asks about sandbox, enable it. Then set the custom image:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
  run --rm --no-deps --entrypoint openclaw openclaw-gateway \
  config set agents.defaults.sandbox.docker.image '"openclaw-sandbox:custom"'
```

**`DOCKER_GID`** — the gateway needs access to the Docker socket to spawn sandbox containers. Set `DOCKER_GID` in `.env` to the host's docker group ID so the `node` user gets the right group membership:

```bash
# Linux:
stat -c '%g' /var/run/docker.sock   # typically 999 or 998
# macOS:
# use 0
```

### Notes

- All OpenClaw state lives in the `openclaw_config` Docker volume at `/home/node/.openclaw/`.
- `openclaw-init` chowns the named volume to uid 1000 on every `up` (named volumes are root-owned by default; the gateway image runs as `node`).
- `openclaw-cli` shares the gateway's network namespace via `network_mode: "service:openclaw-gateway"` so CLI commands can reach the gateway on `127.0.0.1:18789`.
- The gateway token is **never** stored in `openclaw.json` as plaintext — onboarding writes it as `{source: "env", id: "OPENCLAW_GATEWAY_TOKEN"}` and it's resolved at runtime. Rotating the token is just a `.env` edit + `docker compose restart openclaw-gateway`.

## Adding a New Service

1. Create `services/my-app/` with a `Dockerfile`.

2. Add to `docker-compose.yml`:
   ```yaml
   my-app:
     build: ./services/my-app
     container_name: my-app
     labels:
       - "traefik.enable=true"
       - "traefik.http.routers.my-app.rule=Host(`my-app.beshtawi.online`)"
       - "traefik.http.routers.my-app.entrypoints=websecure"
       - "traefik.http.routers.my-app.tls.certresolver=letsencrypt"
       - "traefik.http.services.my-app.loadbalancer.server.port=80"
     networks:
       - public-web
   ```

3. If the service needs a database, add it to `scripts/init-databases.sql`.

4. Push to `main` — CI/CD deploys automatically.

## Local Development

```bash
docker compose up --build
```

Add to `/etc/hosts`:
```
127.0.0.1  beshtawi.online
127.0.0.1  app1.beshtawi.online
127.0.0.1  app2.beshtawi.online
127.0.0.1  admin.beshtawi.online
127.0.0.1  chat.localhost
```

## CI/CD

Pushes to `main` trigger a GitHub Actions workflow that SSHs into the VPS, pulls the latest code, and rebuilds the containers.

Required GitHub repository secrets:
- `VPS_HOST`
- `VPS_USER`
- `VPS_PASSWORD`
