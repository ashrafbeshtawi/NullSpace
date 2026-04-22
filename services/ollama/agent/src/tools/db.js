import { query, shutdown as poolShutdown } from '../db/pool.js';

export function register(registry) {
  registry.register('query_database', {
    type: 'function',
    function: {
      name: 'query_database',
      description: 'Execute a SQL query against the PostgreSQL database. Use parameterized queries for safety.',
      parameters: {
        type: 'object',
        properties: {
          query: { type: 'string', description: 'SQL query to execute' },
          params: {
            type: 'array',
            items: { type: 'string' },
            description: 'Query parameters (for $1, $2, etc.)',
          },
        },
        required: ['query'],
      },
    },
  }, async ({ query: sql, params }) => {
    const result = await query(sql, params || []);
    return {
      rowCount: result.rowCount,
      rows: result.rows?.slice(0, 100),
      command: result.command,
    };
  });
}

export { poolShutdown as shutdown };
