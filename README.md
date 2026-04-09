# NullSpace

Personal multi-service platform running on Docker with Traefik as a reverse proxy.

## Architecture

```
                    Internet
                       |
                 ┌─────┴─────┐
                 │  Traefik   │  :80 (HTTP → HTTPS redirect)
                 │  (proxy)   │  :443 (HTTPS + auto Let's Encrypt)
                 └─────┬──────┘
                       │
          ┌────────────┼────────────┐
          │            │            │
     ┌────┴───┐  ┌─────┴────┐  ┌───┴────┐
     │  app1  │  │  app2    │  │ default │
     │  :80   │  │  :80     │  │  :80    │
     └────────┘  └──────────┘  └─────────┘
```

- **Traefik** auto-discovers services via Docker labels and routes requests based on subdomain
- **Let's Encrypt** certificates are issued and renewed automatically
- **HTTP** requests are redirected to **HTTPS**
- All services share a `public-web` Docker bridge network

## Services

| Service | Subdomain | Description |
|---------|-----------|-------------|
| default | `beshtawi.online` | Main landing page |
| app1 | `app1.beshtawi.online` | Service 1 |
| app2 | `app2.beshtawi.online` | Service 2 |

## Adding a New Service

1. Create a directory under `services/`:
   ```
   services/my-app/
   ├── Dockerfile
   └── (your app files)
   ```

2. Add the service to `docker-compose.yml`:
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

3. Add a DNS A record for the subdomain pointing to the server IP (or use a wildcard `*` record).

4. Push to `main` — CI/CD will deploy automatically.

## Local Development

```bash
docker compose up --build
```

Add entries to `/etc/hosts` for local testing:
```
127.0.0.1  beshtawi.online
127.0.0.1  app1.beshtawi.online
127.0.0.1  app2.beshtawi.online
```

## Traefik Dashboard

The dashboard is bound to localhost only (not publicly accessible). Access it via SSH tunnel:

```bash
ssh -L 8080:localhost:8080 user@your-server
```

Then visit `http://localhost:8080`.

## CI/CD

Pushes to `main` trigger a GitHub Actions workflow that SSHs into the VPS, pulls the latest code, and rebuilds the containers.

Required GitHub repository secrets:
- `VPS_HOST`
- `VPS_USER`
- `VPS_PASSWORD`

## DNS Setup

Set these A records with your domain registrar:

| Type | Host | Value |
|------|------|-------|
| A | `@` | Server IP |
| A | `*` | Server IP |
