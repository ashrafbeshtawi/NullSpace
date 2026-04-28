-- This file runs ONLY on the first initialization of the Postgres volume
-- (via Postgres's docker-entrypoint-initdb.d mechanism). It handles
-- cluster-level setup that Flyway cannot do:
--   - Creating extra databases (Flyway connects to one DB at a time)
--   - Creating roles (roles are cluster-level, not database-level)
--
-- Everything else (tables, grants, indexes, etc.) lives in
-- services/migrations/sql/ and is applied by the Flyway service.

-- Extra databases needed by other services
CREATE DATABASE glitchtip;

-- Restricted role used by the DogeClaw agent's query_database tool.
-- Grants are applied by V2__grant_dogeclaw.sql via Flyway.
DO $$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'dogeclaw') THEN
    CREATE ROLE dogeclaw WITH LOGIN PASSWORD 'dogeclaw-agent-pw';
  END IF;
END
$$;
