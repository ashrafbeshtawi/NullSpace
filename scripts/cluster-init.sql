-- Cluster-level setup that must exist before per-database migrations run.
-- This script is executed by the `postgres-init` one-shot container on
-- every `docker compose up`. Every statement is idempotent — re-running
-- against a populated cluster is a no-op.
--
-- Scope here is intentionally limited to cluster-wide concerns:
--   - extra databases (Postgres only creates POSTGRES_DB)
--   - cross-service roles + grants
-- Per-database schema changes (tables, columns, indexes) belong in the
-- owning service's own migrations, not here.

-- ============================================================
-- glitchtip
-- ============================================================
SELECT 'CREATE DATABASE glitchtip'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'glitchtip')\gexec

-- ============================================================
-- dogeclaw: isolated database with admin + agent roles.
-- The agent's grants are intentionally broad today (CRUD on every table
-- the admin creates) because dogeclaw's app does not yet differentiate
-- between the two URLs. The meaningful split right now is that the agent
-- role cannot ALTER/DROP schema or modify grants. Tighten further when
-- dogeclaw's migrations learn to grant agent writes per-table.
-- ============================================================
SELECT 'CREATE DATABASE dogeclaw'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'dogeclaw')\gexec

SELECT 'CREATE ROLE dogeclaw_admin LOGIN'
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'dogeclaw_admin')\gexec
ALTER ROLE dogeclaw_admin PASSWORD :'admin_pw';

SELECT 'CREATE ROLE dogeclaw_agent LOGIN'
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'dogeclaw_agent')\gexec
ALTER ROLE dogeclaw_agent PASSWORD :'agent_pw';

ALTER DATABASE dogeclaw OWNER TO dogeclaw_admin;
GRANT CONNECT ON DATABASE dogeclaw TO dogeclaw_agent;

\connect dogeclaw

GRANT USAGE ON SCHEMA public TO dogeclaw_agent;

-- Cover any tables already created on a prior run (re-deploy ordering).
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO dogeclaw_agent;
GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO dogeclaw_agent;

-- Anything dogeclaw_admin creates in `public` from now on inherits these.
ALTER DEFAULT PRIVILEGES FOR ROLE dogeclaw_admin IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO dogeclaw_agent;
ALTER DEFAULT PRIVILEGES FOR ROLE dogeclaw_admin IN SCHEMA public
    GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO dogeclaw_agent;
