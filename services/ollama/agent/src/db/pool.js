import pg from 'pg';
import config from '../config.js';

let pool = null;

export function getPool() {
  if (!pool) {
    if (!config.database.url) throw new Error('No database configured (DOGECLAW_DATABASE_URL not set)');
    pool = new pg.Pool({ connectionString: config.database.url });
  }
  return pool;
}

export async function query(sql, params) {
  return getPool().query(sql, params);
}

export async function shutdown() {
  if (pool) { await pool.end(); pool = null; }
}
