-- Cluster-level setup that must exist before per-database migrations run.
-- This script is executed by the `postgres-init` one-shot container on
-- every `docker compose up`, connected as the cluster superuser. Every
-- statement is idempotent — re-running against a populated cluster is a
-- no-op (apart from re-syncing role passwords from .env).
--
-- Scope is intentionally limited to cluster-wide concerns (databases,
-- roles, database-level grants). Per-database schema changes (tables,
-- columns, indexes) belong in the owning service's own migrations.
--
-- Each app gets its own LOGIN role that owns its database and nothing
-- else; PUBLIC loses CONNECT on every app database, so one app's
-- credentials can't open another app's data. The superuser is reserved for
-- this script, backups/restores and DbGate. Passwords come from the
-- <APP>_DB_PASSWORD variables compose passes into the postgres-init
-- container (read with \getenv so they never appear in a command line).

-- DogeClaw's restricted agent role. Its name and password are hardcoded in
-- the dogeclaw image (agent/src/lib/databaseUrls.js) and its V2 migration
-- creates it if missing — but that migration now runs as the non-superuser
-- dogeclaw_admin role, which can't CREATE ROLE. Creating it here first turns
-- V2 into a no-op. An existing role (and any rotated password) is left alone.
SELECT 'CREATE ROLE dogeclaw LOGIN PASSWORD ''dogeclaw-agent-pw'''
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'dogeclaw')\gexec

\set app_role glitchtip
\set app_db glitchtip
\set app_password ''
\getenv app_password GLITCHTIP_DB_PASSWORD
\ir app-database.sql

\set app_role dogeclaw_admin
\set app_db dogeclaw
\set app_password ''
\getenv app_password DOGECLAW_DB_PASSWORD
\ir app-database.sql

\set app_role telebot
\set app_db telebot
\set app_password ''
\getenv app_password TELEBOT_DB_PASSWORD
\ir app-database.sql

\set app_role portfolio
\set app_db portfolio
\set app_password ''
\getenv app_password PORTFOLIO_DB_PASSWORD
\ir app-database.sql

-- The datenflow service (ex-kiwelt) keeps its original `kiwelt` database.
\set app_role datenflow
\set app_db kiwelt
\set app_password ''
\getenv app_password DATENFLOW_DB_PASSWORD
\ir app-database.sql

\set app_role mockingbird
\set app_db mockingbird
\set app_password ''
\getenv app_password MOCKINGBIRD_DB_PASSWORD
\ir app-database.sql

\unset app_password

-- The agent role may connect to dogeclaw (its V3 migration grants this too,
-- but only after the app's first boot) and to nothing else. The explicit
-- REVOKEs clear any grant an earlier setup may have left behind.
GRANT CONNECT ON DATABASE dogeclaw TO dogeclaw;
REVOKE CONNECT ON DATABASE glitchtip, telebot, portfolio, kiwelt, mockingbird FROM dogeclaw;

-- The default database (POSTGRES_DB) is the superuser's own; no app uses it.
\set cluster_db ''
\getenv cluster_db POSTGRES_DB
SELECT format('REVOKE CONNECT ON DATABASE %I FROM PUBLIC', datname)
FROM pg_database
WHERE datname = :'cluster_db' AND datname NOT IN ('postgres', 'template0', 'template1')\gexec
