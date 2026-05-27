#!/bin/bash
# Give www-data (the PHP/Apache user) permission to talk to the host docker
# daemon through the mounted socket. The socket's GID varies by host:
#   - typical Linux:        the host's `docker` group GID (e.g. 999)
#   - Docker Desktop / Mac: root (GID 0)
#
# Strategy: find (or reuse) a group with the socket's GID and add www-data to
# it. Never try to create a new group on a GID that's already taken.
set -e

if [ -S /var/run/docker.sock ]; then
  SOCK_GID=$(stat -c '%g' /var/run/docker.sock)

  # Does a group with this GID already exist?
  EXISTING_GROUP=$(getent group "$SOCK_GID" | cut -d: -f1 || true)

  if [ -n "$EXISTING_GROUP" ]; then
    GROUP_NAME="$EXISTING_GROUP"
  else
    GROUP_NAME="docker"
    if getent group docker > /dev/null; then
      groupmod -g "$SOCK_GID" docker
    else
      groupadd -g "$SOCK_GID" docker
    fi
  fi

  usermod -aG "$GROUP_NAME" www-data
fi

# Ensure backup dir is writable by www-data. This is a bind-mount of the
# host's /var/backups/nullspace, so the chown propagates to the host inode.
# Root on the host can still write either way (root bypasses perms).
if [ -d /var/backups/nullspace ]; then
  chown www-data:www-data /var/backups/nullspace || true
fi

exec apache2-foreground
