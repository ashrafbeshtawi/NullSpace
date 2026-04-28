# Cheat Sheet

## Compose

```bash
# Start everything (no Ollama)
docker compose up -d

# Start with build
docker compose up --build -d

# Stop everything
docker compose down

# Restart one service
docker compose restart dogeclaw

# Rebuild one service
docker compose build dogeclaw && docker compose up -d dogeclaw

# Validate config
docker compose config --services
docker compose --profile manual config --services

# Prod compose
docker compose -f docker-compose.yml -f docker-compose.prod.yml up --build -d
```

## Ollama (manual)

```bash
# Start
docker compose --profile manual up -d ollama

# Build + start
docker compose --profile manual up --build -d ollama

# Stop
docker compose stop ollama

# Pull model
docker exec ollama ollama pull gemma4:e2b
docker exec ollama ollama pull qwen3:8b
docker exec ollama ollama pull llama3.1:8b

# List models
docker exec ollama ollama list

# Delete model
docker exec ollama ollama rm <model>

# Test from dogeclaw container
docker exec dogeclaw curl -s http://ollama:11434/api/tags
```

## DogeClaw

```bash
# Logs
docker exec dogeclaw cat /root/agent-workspace/logs/agent.log
docker exec dogeclaw tail -f /root/agent-workspace/logs/agent.log

# Restart agent (without container restart)
docker compose restart dogeclaw

# Shell into container
docker exec -it dogeclaw bash

# Login API
curl -s -X POST http://dogeclaw.localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"user":"admin","password":"changeme"}' -c /tmp/dc

# List agents
curl -s -b /tmp/dc http://dogeclaw.localhost:8000/api/agents

# Test chat (SSE)
curl -s -N -b /tmp/dc -X POST http://dogeclaw.localhost:8000/api/chat \
  -H "Content-Type: application/json" \
  -d '{"message":"hi","agentId":1}'
```

## PostgreSQL

```bash
# psql shell
docker exec -it postgres psql -U nullspace -d nullspace

# List tables
docker exec postgres psql -U nullspace -d nullspace -c '\dt'

# Show models
docker exec postgres psql -U nullspace -d nullspace -c 'SELECT id,name,provider,model_id FROM models;'

# Show agents
docker exec postgres psql -U nullspace -d nullspace -c 'SELECT id,name,model_id FROM agents;'

# Show channels
docker exec postgres psql -U nullspace -d nullspace -c 'SELECT id,name,agent_id,enabled FROM channels;'

# Show skills
docker exec postgres psql -U nullspace -d nullspace -c 'SELECT id,name FROM skills;'

# Create dogeclaw role (prod, one-time)
docker exec -it postgres psql -U nullspace -d nullspace -c "
  CREATE ROLE dogeclaw WITH LOGIN PASSWORD 'dogeclaw-agent-pw';
  GRANT CONNECT ON DATABASE nullspace TO dogeclaw;
  GRANT USAGE ON SCHEMA public TO dogeclaw;
  GRANT CREATE ON SCHEMA public TO dogeclaw;
  GRANT SELECT ON models, agents, channels, skills, agent_skills TO dogeclaw;
"
```

## Volumes

```bash
# List
docker volume ls | grep nullspace

# Inspect
docker volume inspect nullspace_agent_workspace
docker volume inspect nullspace_ollama_data

# Remove (data loss!)
docker volume rm nullspace_agent_workspace
docker volume rm nullspace_ollama_data
```

## Logs

```bash
# Container stdout/stderr
docker logs dogeclaw --tail 50
docker logs ollama --tail 50
docker logs traefik --tail 50

# Follow
docker logs -f dogeclaw

# Agent app log file (file-based)
docker exec dogeclaw cat /root/agent-workspace/logs/agent.log
```

## Telegram

```bash
# Test bot token
curl "https://api.telegram.org/bot<TOKEN>/getMe"

# Check webhook status
curl "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"

# Set webhook manually
curl "https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://dogeclaw.beshtawi.online/webhook/<bot_name>"

# Delete webhook
curl "https://api.telegram.org/bot<TOKEN>/deleteWebhook"

# Get pending updates (only if no webhook)
curl "https://api.telegram.org/bot<TOKEN>/getUpdates"
```

## Build / Deploy

```bash
# Generate package-lock for agent
npm install --prefix services/dogeclaw/agent --package-lock-only
rm -rf services/dogeclaw/agent/node_modules

# Local rebuild + restart loop
npm install --prefix services/dogeclaw/agent --package-lock-only && \
  rm -rf services/dogeclaw/agent/node_modules && \
  docker compose build dogeclaw && \
  docker compose up -d dogeclaw

# Push to deploy (triggers GitHub Actions)
git push origin main
```

## Traefik

```bash
# List routers
docker exec traefik wget -qO- http://localhost:8080/api/http/routers | python3 -m json.tool | head -50

# Check specific service
docker exec traefik wget -qO- http://localhost:8080/api/http/services | grep -A2 dogeclaw
```

## Env generation

```bash
# bcrypt for BASIC_AUTH_USERS (escape $ for compose)
echo $(htpasswd -nB admin) | sed -e 's/\$/\$\$/g'

# Random secret
openssl rand -hex 32

# Random password
openssl rand -base64 24
```

## Whisper (audio)

```bash
# Test transcription inside container
docker exec dogeclaw whisper /tmp/audio.ogg --model base --output_format txt --output_dir /tmp
```

## Cleanup

```bash
# Stop + remove containers, keep volumes
docker compose down

# Stop + remove containers + volumes (data loss!)
docker compose down -v

# Remove dangling images
docker image prune

# Full reset (data loss!)
docker compose down -v && docker system prune -af
```

## URLs (dev)

```
http://dogeclaw.localhost:8000          DogeClaw chat
http://dogeclaw.localhost:8000/admin    DogeClaw admin
http://admin.localhost:8000             Admin dashboard
http://traefik.localhost:8000           Traefik dashboard (no auth in dev)
http://portainer.localhost:8000         Portainer
http://status.localhost:8000            Uptime Kuma
http://errors.localhost:8000            GlitchTip
http://localhost:8000                   Main site
http://localhost:8080                   Traefik insecure dashboard
```

## URLs (prod)

```
https://dogeclaw.beshtawi.online        DogeClaw
https://admin.beshtawi.online           Admin (basic auth)
https://traefik.beshtawi.online         Traefik (basic auth)
https://portainer.beshtawi.online       Portainer
https://status.beshtawi.online          Uptime Kuma
https://errors.beshtawi.online          GlitchTip
```

## Ports

```
80      Traefik HTTP (prod)
443     Traefik HTTPS (prod)
8000    Traefik HTTP (dev)
8080    Traefik insecure dashboard (dev)
3000    DogeClaw (internal)
11434   Ollama (internal)
5432    Postgres (internal)
6379    Redis (internal)
```
