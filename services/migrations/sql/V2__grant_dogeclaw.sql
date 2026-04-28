-- Grant the restricted dogeclaw role:
--   - CONNECT on this database
--   - SELECT on config tables (read-only access for the agent)
--   - USAGE + CREATE on schema public (so the agent can manage its own tables)
--
-- The role itself is created by scripts/init-databases.sql on Postgres init.
-- This is wrapped in a DO block so it skips silently if the role doesn't exist
-- (e.g. on a non-standard local setup).

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'dogeclaw') THEN
    EXECUTE format('GRANT CONNECT ON DATABASE %I TO dogeclaw', current_database());
    EXECUTE 'GRANT SELECT ON models, agents, channels, skills, agent_skills TO dogeclaw';
    EXECUTE 'GRANT USAGE ON SCHEMA public TO dogeclaw';
    EXECUTE 'GRANT CREATE ON SCHEMA public TO dogeclaw';
  END IF;
END
$$;
