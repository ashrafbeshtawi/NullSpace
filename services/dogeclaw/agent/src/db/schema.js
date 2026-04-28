import { adminQuery } from './pool.js';
import config from '../config.js';

export async function migrate() {
  // Models table
  await adminQuery(`
    CREATE TABLE IF NOT EXISTS models (
      id SERIAL PRIMARY KEY,
      name TEXT NOT NULL UNIQUE,
      provider TEXT NOT NULL DEFAULT 'ollama',
      base_url TEXT NOT NULL DEFAULT 'http://ollama:11434',
      model_id TEXT NOT NULL,
      api_key TEXT,
      think BOOLEAN NOT NULL DEFAULT false,
      accepts JSONB NOT NULL DEFAULT '["text"]',
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )
  `);

  // Add api_key column if upgrading from old schema
  try { await adminQuery(`ALTER TABLE models ADD COLUMN IF NOT EXISTS api_key TEXT`); } catch {}

  // Agents table — references model
  await adminQuery(`
    CREATE TABLE IF NOT EXISTS agents (
      id SERIAL PRIMARY KEY,
      name TEXT NOT NULL UNIQUE,
      system_prompt TEXT NOT NULL DEFAULT '',
      model_id INTEGER REFERENCES models(id) ON DELETE SET NULL,
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )
  `);

  // Add model_id column if upgrading from old schema (agents had model/think inline)
  try {
    await adminQuery(`ALTER TABLE agents ADD COLUMN IF NOT EXISTS model_id INTEGER REFERENCES models(id) ON DELETE SET NULL`);
  } catch {}
  // Drop old columns if they exist (from previous schema)
  try { await adminQuery(`ALTER TABLE agents DROP COLUMN IF EXISTS model`); } catch {}
  try { await adminQuery(`ALTER TABLE agents DROP COLUMN IF EXISTS think`); } catch {}

  // Skills table
  await adminQuery(`
    CREATE TABLE IF NOT EXISTS skills (
      id SERIAL PRIMARY KEY,
      name TEXT NOT NULL UNIQUE,
      description TEXT NOT NULL DEFAULT '',
      content TEXT NOT NULL DEFAULT '',
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )
  `);

  // Agent <-> Skills join (empty assignment = skill available to all agents)
  await adminQuery(`
    CREATE TABLE IF NOT EXISTS agent_skills (
      agent_id INTEGER NOT NULL REFERENCES agents(id) ON DELETE CASCADE,
      skill_id INTEGER NOT NULL REFERENCES skills(id) ON DELETE CASCADE,
      PRIMARY KEY (agent_id, skill_id)
    )
  `);

  // Channels table
  await adminQuery(`
    CREATE TABLE IF NOT EXISTS channels (
      id SERIAL PRIMARY KEY,
      agent_id INTEGER NOT NULL REFERENCES agents(id) ON DELETE CASCADE,
      type TEXT NOT NULL,
      name TEXT NOT NULL,
      config JSONB NOT NULL DEFAULT '{}',
      response_mode TEXT NOT NULL DEFAULT 'immediate',
      response_interval TEXT,
      enabled BOOLEAN NOT NULL DEFAULT true,
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )
  `);

  // Grant dogeclaw user read-only on config tables + CREATE on schema
  try {
    const { rowCount } = await adminQuery(`SELECT 1 FROM pg_roles WHERE rolname = 'dogeclaw'`);
    if (rowCount > 0) {
      await adminQuery(`GRANT SELECT ON models, agents, channels, skills, agent_skills TO dogeclaw`);
      await adminQuery(`GRANT CREATE ON SCHEMA public TO dogeclaw`);
      await adminQuery(`GRANT USAGE ON SCHEMA public TO dogeclaw`);
      console.log('[db] Granted dogeclaw read-only on config tables');
    }
  } catch (err) {
    console.log(`[db] Could not set dogeclaw permissions: ${err.message}`);
  }

  // Seed default model if none exist
  const modelsResult = await adminQuery('SELECT 1 FROM models LIMIT 1');
  if (modelsResult.rowCount === 0) {
    await adminQuery(
      `INSERT INTO models (name, provider, base_url, model_id, think, accepts) VALUES ($1, $2, $3, $4, $5, $6)`,
      ['gemma4-e2b', 'ollama', config.ollama.url, config.ollama.model, config.ollama.think, JSON.stringify(['text', 'image'])],
    );
    console.log('[db] Seeded default model');
  }

  // Seed default agent if none exist
  const agentsResult = await adminQuery('SELECT 1 FROM agents LIMIT 1');
  if (agentsResult.rowCount === 0) {
    const modelRow = await adminQuery('SELECT id FROM models LIMIT 1');
    await adminQuery(
      `INSERT INTO agents (name, system_prompt, model_id) VALUES ($1, $2, $3)`,
      [
        'default',
        'You are DogeClaw, a helpful personal AI agent running inside a Docker container. You have tools to run shell commands, manage files, schedule cron jobs, and query a database. Be concise and practical.',
        modelRow.rows[0]?.id || null,
      ]
    );
    console.log('[db] Seeded default agent');
  }

  console.log('[db] Schema ready');
}
