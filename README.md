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
        ┌───────────┬───────┼───────────┐
        │           │       │           │
   ┌────┴───┐ ┌─────┴──┐ ┌─┴──┐ ┌──────┴──┐
   │ Admin  │ │Portainer│ │Apps│ │GlitchTip│
   │ Panel  │ │        │ │    │ │(errors) │
   └────────┘ └────────┘ └──┬─┘ └────┬────┘
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
| Ollama | `ollama.beshtawi.online` | Local LLM inference (basic auth) |

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

Fill in all values in `.env` — database credentials, GlitchTip secret key, and the password hash.

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
```

## CI/CD

Pushes to `main` trigger a GitHub Actions workflow that SSHs into the VPS, pulls the latest code, and rebuilds the containers.

Required GitHub repository secrets:
- `VPS_HOST`
- `VPS_USER`
- `VPS_PASSWORD`
