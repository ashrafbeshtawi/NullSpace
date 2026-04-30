-- This file runs ONLY on the first initialization of the Postgres volume
-- (via Postgres's docker-entrypoint-initdb.d mechanism). It handles
-- cluster-level setup that Flyway cannot do:
--   - Creating extra databases (Flyway connects to one DB at a time)
--
-- Per-service schema, role creation, and grants live in each service's own
-- Flyway migrations under services/<svc>/migrations/sql/.

-- Extra databases needed by other services
CREATE DATABASE glitchtip;
