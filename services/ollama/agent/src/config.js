const env = process.env;

const config = {
  ollama: {
    url: env.DOGECLAW_OLLAMA_URL || 'http://127.0.0.1:11434',
    model: env.DOGECLAW_MODEL || 'gemma4:e2b',
    think: env.DOGECLAW_THINK === 'true',
  },
  web: {
    port: parseInt(env.DOGECLAW_PORT || '3000', 10),
    user: env.DOGECLAW_WEB_USER || 'admin',
    password: env.DOGECLAW_WEB_PASSWORD || 'changeme',
    secret: env.DOGECLAW_WEB_SECRET || 'dogeclaw-default-secret-change-me',
  },
  database: {
    url: env.DOGECLAW_DATABASE_URL || null,
  },
  telegram: {
    mode: env.DOGECLAW_TELEGRAM_MODE || 'polling',
    webhookUrl: env.DOGECLAW_WEBHOOK_URL || '',
  },
  workspace: env.DOGECLAW_WORKSPACE || '/root/agent-workspace',
};

config.paths = {
  files: `${config.workspace}/files`,
  sessions: `${config.workspace}/sessions`,
  queues: `${config.workspace}/queues`,
  logs: `${config.workspace}/logs`,
  cronFile: `${config.workspace}/cron.json`,
  mcpConfigFile: `${config.workspace}/mcp-config.json`,
};

export default config;
