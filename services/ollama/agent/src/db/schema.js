import { query } from './pool.js';
import config from '../config.js';

export async function migrate() {
  await query(`
    CREATE TABLE IF NOT EXISTS agents (
      id SERIAL PRIMARY KEY,
      name TEXT NOT NULL UNIQUE,
      system_prompt TEXT NOT NULL DEFAULT '',
      model TEXT NOT NULL DEFAULT '${config.ollama.model}',
      think BOOLEAN NOT NULL DEFAULT false,
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )
  `);

  await query(`
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

  // Seed default agent if none exist
  const { rowCount } = await query('SELECT 1 FROM agents LIMIT 1');
  if (rowCount === 0) {
    await query(
      `INSERT INTO agents (name, system_prompt, model, think) VALUES ($1, $2, $3, $4)`,
      [
        'default',
        'You are DogeClaw, a helpful personal AI agent running inside a Docker container. You have tools to run shell commands, manage files, schedule cron jobs, and query a database. Be concise and practical.',
        config.ollama.model,
        config.ollama.think,
      ]
    );
    console.log('[db] Seeded default agent');
  }

  console.log('[db] Schema ready');
}
