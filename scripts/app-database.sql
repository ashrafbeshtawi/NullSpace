-- Per-app database + role. Included by cluster-init.sql via \ir, once per
-- app, with these psql variables set:
--   app_role      LOGIN role the app connects as
--   app_db        database the role owns
--   app_password  the role's password (from <APP>_DB_PASSWORD in .env)
-- Starts and ends connected to the `postgres` database. Every step is
-- idempotent, so re-running it on every `docker compose up` is safe.

-- Refuse an empty password rather than create a role nobody can log in as
-- (or worse, one whose URL silently points at a different role's secret).
SELECT format(
  'DO $fail$ BEGIN RAISE EXCEPTION %L; END $fail$',
  'cluster-init: password for role "' || :'app_role' || '" is empty, set it in .env')
WHERE :'app_password' = ''\gexec

SELECT format('CREATE ROLE %I LOGIN', :'app_role')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'app_role')\gexec

-- Re-asserted on every run: keeps the password in sync with .env and the
-- role free of cluster-wide powers even if someone ALTERed it by hand.
ALTER ROLE :"app_role" WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE
  NOREPLICATION NOBYPASSRLS PASSWORD :'app_password';

SELECT format('CREATE DATABASE %I OWNER %I', :'app_db', :'app_role')
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'app_db')\gexec

ALTER DATABASE :"app_db" OWNER TO :"app_role";

-- Only the owner (and roles granted CONNECT explicitly) may connect.
REVOKE CONNECT ON DATABASE :"app_db" FROM PUBLIC;

-- Hand everything the cluster superuser created inside the app's database
-- over to the app role. Before per-app roles existed every app connected as
-- the superuser, so on an existing cluster all schemas, tables, sequences,
-- views, types and functions are owned by it; on a fresh cluster (and on
-- every later run) these queries find nothing. Extension members stay with
-- the superuser, and sequences owned by a table column follow their table.
\connect :app_db

SELECT format('ALTER SCHEMA %I OWNER TO %I', n.nspname, :'app_role')
FROM pg_namespace n
WHERE n.nspowner = (SELECT oid FROM pg_roles WHERE rolname = current_user)
  AND n.nspname <> 'information_schema'
  AND n.nspname NOT LIKE 'pg\_%'
  AND NOT EXISTS (SELECT 1 FROM pg_depend d
                  WHERE d.classid = 'pg_namespace'::regclass
                    AND d.objid = n.oid AND d.deptype = 'e')\gexec

SELECT format('ALTER %s %s OWNER TO %I',
              CASE c.relkind
                WHEN 'S' THEN 'SEQUENCE'
                WHEN 'v' THEN 'VIEW'
                WHEN 'm' THEN 'MATERIALIZED VIEW'
                WHEN 'f' THEN 'FOREIGN TABLE'
                WHEN 'c' THEN 'TYPE'
                ELSE 'TABLE'
              END,
              c.oid::regclass, :'app_role')
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE c.relowner = (SELECT oid FROM pg_roles WHERE rolname = current_user)
  AND c.relkind IN ('r', 'p', 'v', 'm', 'S', 'f', 'c')
  AND n.nspname <> 'information_schema'
  AND n.nspname NOT LIKE 'pg\_%'
  AND NOT EXISTS (SELECT 1 FROM pg_depend d
                  WHERE d.classid = 'pg_class'::regclass AND d.objid = c.oid
                    AND (d.deptype = 'e'
                         OR (c.relkind = 'S' AND d.deptype IN ('a', 'i')
                             AND d.refclassid = 'pg_class'::regclass)))
ORDER BY c.oid\gexec

SELECT format('ALTER %s %s OWNER TO %I',
              CASE t.typtype WHEN 'd' THEN 'DOMAIN' ELSE 'TYPE' END,
              t.oid::regtype, :'app_role')
FROM pg_type t
JOIN pg_namespace n ON n.oid = t.typnamespace
WHERE t.typowner = (SELECT oid FROM pg_roles WHERE rolname = current_user)
  AND t.typtype IN ('e', 'd', 'r')
  AND n.nspname <> 'information_schema'
  AND n.nspname NOT LIKE 'pg\_%'
  AND NOT EXISTS (SELECT 1 FROM pg_depend d
                  WHERE d.classid = 'pg_type'::regclass
                    AND d.objid = t.oid AND d.deptype = 'e')\gexec

SELECT format('ALTER %s %s OWNER TO %I',
              CASE p.prokind WHEN 'a' THEN 'AGGREGATE' ELSE 'ROUTINE' END,
              p.oid::regprocedure, :'app_role')
FROM pg_proc p
JOIN pg_namespace n ON n.oid = p.pronamespace
WHERE p.proowner = (SELECT oid FROM pg_roles WHERE rolname = current_user)
  AND n.nspname <> 'information_schema'
  AND n.nspname NOT LIKE 'pg\_%'
  AND NOT EXISTS (SELECT 1 FROM pg_depend d
                  WHERE d.classid = 'pg_proc'::regclass
                    AND d.objid = p.oid AND d.deptype = 'e')\gexec

\connect postgres
