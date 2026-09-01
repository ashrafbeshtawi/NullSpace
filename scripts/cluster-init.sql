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

SELECT 'CREATE DATABASE portfolio'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'portfolio')\gexec

-- Transition: the `kiwelt` database was renamed to `datenflow` (Sep 2026).
-- Rename in place to keep prod data; kick any lingering connections first
-- (the old kiwelt container may still be up when this runs). No-op once done.
SELECT pg_terminate_backend(pid) FROM pg_stat_activity
WHERE datname = 'kiwelt' AND pid <> pg_backend_pid();

SELECT 'ALTER DATABASE kiwelt RENAME TO datenflow'
WHERE EXISTS (SELECT 1 FROM pg_database WHERE datname = 'kiwelt')
  AND NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'datenflow')\gexec

SELECT 'CREATE DATABASE datenflow'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'datenflow')\gexec
