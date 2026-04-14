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

OpenClaw runs as a containerized AI assistant connected to OpenRouter.

### Configuration

All gateway config is seeded automatically on container start via env vars — no manual `docker exec` steps needed after deploy:

| Env var | Set in | Purpose |
|---------|--------|---------|
| `OPENROUTER_API_KEY` | `.env` | OpenRouter API key for LLM inference |
| `OPENCLAW_GATEWAY_TOKEN` | `.env` | Auth token browsers must present to connect |
| `OPENCLAW_DEFAULT_MODEL` | `docker-compose.yml` | Default LLM model (`openrouter/auto`) |
| `OPENCLAW_ALLOWED_ORIGINS` | `override.yml` / `prod.yml` | Origins allowed to open the Control UI |
| `OPENCLAW_TRUSTED_PROXIES` | `docker-compose.yml` | Private IP ranges Traefik forwards from |

The entrypoint writes these into `openclaw.json` before the gateway starts, so a fresh volume gets the right config immediately.

**Trusted proxies** are set to the standard RFC 1918 private ranges (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`). This tells OpenClaw to trust `X-Forwarded-For` headers from Docker-internal IPs (Traefik), so it can see the real client IP instead of the container network address.

### Pairing a browser

Each new browser must be paired with the gateway:

1. Open the Control UI (`https://chat.beshtawi.online` or `http://chat.localhost:8000`).
2. Paste the `OPENCLAW_GATEWAY_TOKEN` from `.env` and click Connect.
3. The UI will show "pairing required". On the server, approve it:

```bash
docker exec openclaw openclaw devices list          # find the pending request ID
docker exec openclaw openclaw devices approve <id>   # approve it
```

### Managing devices

```bash
docker exec openclaw openclaw devices list     # list all paired/pending devices
docker exec openclaw openclaw devices remove   # remove a paired device
docker exec openclaw openclaw devices revoke   # revoke a device token
```

### Notes

- All OpenClaw config is stored in the `openclaw_config` Docker volume at `/home/node/.openclaw/`.
- The entrypoint runs as root to fix volume permissions (`chown`), seeds `openclaw.json` from env vars, then drops to the `node` user for the gateway process.
- Use `-u node` when running `openclaw config set` via `docker exec` so writes go to the correct config file (not `/root/.openclaw/`).

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
