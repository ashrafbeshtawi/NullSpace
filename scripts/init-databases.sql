CREATE DATABASE glitchtip;

-- DogeClaw restricted user (agent tool access)
DO $$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'dogeclaw') THEN
    CREATE ROLE dogeclaw WITH LOGIN PASSWORD 'dogeclaw-agent-pw';
  END IF;
END
$$;

GRANT CONNECT ON DATABASE nullspace TO dogeclaw;
