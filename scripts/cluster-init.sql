-- Cluster-level setup that must exist before per-database migrations run.
-- This script is executed by the `postgres-init` one-shot container on
-- every `docker compose up`. Every statement is idempotent — re-running
-- against a populated cluster is a no-op.
--
-- Scope is intentionally limited to cluster-wide concerns (extra databases,
-- cross-service roles + grants). Per-database schema changes (tables,
-- columns, indexes) belong in the owning service's own migrations.

SELECT 'CREATE DATABASE glitchtip'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'glitchtip')\gexec

SELECT 'CREATE DATABASE dogeclaw'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'dogeclaw')\gexec

SELECT 'CREATE DATABASE telebot'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'telebot')\gexec
