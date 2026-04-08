#!/bin/bash
set -e

mkdir -p /etc/systemd/system/docker.service.d

cat > /etc/systemd/system/docker.service.d/min_api_version.conf <<'EOF'
[Service]
Environment="DOCKER_MIN_API_VERSION=1.24"
EOF

systemctl daemon-reload
systemctl restart docker

cd /opt/NullSpace
docker compose up --build -d

echo "Done. Checking traefik logs..."
docker compose logs traefik --tail 20
