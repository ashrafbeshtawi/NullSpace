import express from 'express';
import { createHmac, randomUUID } from 'node:crypto';
import { readFile, writeFile, readdir, mkdir, unlink } from 'node:fs/promises';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import config from '../config.js';
import { query } from '../db/pool.js';
import { transcribeAudio } from '../audio.js';

const __dirname = dirname(fileURLToPath(import.meta.url));

function sign(data) {
  return createHmac('sha256', config.web.secret).update(data).digest('hex');
}

function authMiddleware(req, res, next) {
  const token = req.headers.cookie?.match(/dogeclaw_token=([^;]+)/)?.[1];
  if (!token || sign('authenticated') !== token) {
    // For page requests, redirect to login
    if (req.accepts('html')) return res.redirect('/login');
    return res.status(401).json({ error: 'unauthorized' });
  }
  next();
}

let telegramManager = null;

export function setTelegramManager(tm) {
  telegramManager = tm;
}

export function createWebServer(agent) {
  const app = express();
  app.use(express.json({ limit: '50mb' }));

  // Static files
  app.use('/static', express.static(join(__dirname, 'public')));

  // --- Auth ---
  app.get('/login', (req, res) => res.sendFile(join(__dirname, 'public', 'login.html')));

  app.post('/api/login', (req, res) => {
    const { user, password } = req.body;
    if (user === config.web.user && password === config.web.password) {
      const token = sign('authenticated');
      res.setHeader('Set-Cookie', `dogeclaw_token=${token}; Path=/; HttpOnly; SameSite=Strict; Max-Age=86400`);
      return res.json({ ok: true });
    }
    res.status(401).json({ error: 'invalid credentials' });
  });

  app.post('/api/logout', (req, res) => {
    res.setHeader('Set-Cookie', 'dogeclaw_token=; Path=/; HttpOnly; Max-Age=0');
    res.json({ ok: true });
  });

  // --- Protected pages ---
  app.get('/', authMiddleware, (req, res) => res.sendFile(join(__dirname, 'public', 'index.html')));
  app.get('/admin', authMiddleware, (req, res) => res.sendFile(join(__dirname, 'public', 'admin.html')));

  // --- Public config (non-sensitive) ---
  app.get('/api/config', authMiddleware, (req, res) => {
    res.json({
      telegramMode: config.telegram.mode,
      webhookUrl: config.telegram.webhookUrl || null,
    });
  });

  // --- Sessions ---
  app.get('/api/sessions', authMiddleware, async (req, res) => {
    try {
      await mkdir(config.paths.sessions, { recursive: true });
      const files = await readdir(config.paths.sessions);
      const sessions = [];
      for (const f of files.filter(f => f.endsWith('.json'))) {
        try {
          const data = JSON.parse(await readFile(join(config.paths.sessions, f), 'utf-8'));
          const id = f.replace('.json', '');
          const lastMsg = data.messages?.[data.messages.length - 1]?.content?.slice(0, 60) || '';
          sessions.push({ id, agentId: data.agentId, agentName: data.agentName, source: data.source || 'web', preview: lastMsg });
        } catch {}
      }
      res.json({ sessions });
    } catch { res.json({ sessions: [] }); }
  });

  app.get('/api/sessions/:id', authMiddleware, async (req, res) => {
    try {
      const file = join(config.paths.sessions, `${req.params.id}.json`);
      const data = JSON.parse(await readFile(file, 'utf-8'));
      res.json(data);
    } catch { res.status(404).json({ error: 'not found' }); }
  });

  app.delete('/api/sessions/:id', authMiddleware, async (req, res) => {
    try {
      await unlink(join(config.paths.sessions, `${req.params.id}.json`));
      res.json({ ok: true });
    } catch { res.status(404).json({ error: 'not found' }); }
  });

  // --- Chat (SSE streaming) ---
  app.post('/api/chat', authMiddleware, async (req, res) => {
    const { message, sessionId: reqSessionId, agentId, images, audio, audioMime } = req.body;
    if (!message && !images?.length && !audio) return res.status(400).json({ error: 'message, images, or audio required' });

    const sid = reqSessionId || randomUUID();
    const sessionData = await loadSession(sid);
    const history = sessionData.messages || [];

    // Load agent config
    let agentConfig = null;
    const aid = agentId || sessionData.agentId || 1;
    try {
      const result = await query('SELECT * FROM agents WHERE id = $1', [aid]);
      agentConfig = result.rows[0];
    } catch {}

    // SSE headers
    res.writeHead(200, {
      'Content-Type': 'text/event-stream',
      'Cache-Control': 'no-cache',
      Connection: 'keep-alive',
    });

    const send = (event, data) => {
      res.write(`event: ${event}\ndata: ${JSON.stringify(data)}\n\n`);
    };

    try {
      // Transcribe audio if present
      let userMessage = message || '';
      if (audio) {
        send('status', 'Transcribing audio...');
        const transcript = await transcribeAudio(audio, audioMime);
        // Combine original message with transcript if both present
        userMessage = userMessage ? `${userMessage}\n\n[Voice message]: ${transcript}` : transcript;
        send('transcript', transcript);
      }
      // If only images with no text, provide a default prompt
      if (!userMessage && images?.length) {
        userMessage = 'What do you see in this image?';
      }

      let fullContent = '';
      let fullThinking = '';

      const reply = await agent.run(userMessage, history, {
        systemPrompt: agentConfig?.system_prompt,
        model: agentConfig?.model,
        think: agentConfig?.think,
        images: images || undefined,
        onEvent: (type, data) => {
          if (type === 'thinking') { fullThinking += data; send('thinking', data); }
          else if (type === 'content') { fullContent += data; send('content', data); }
          else if (type === 'tool_calls') { send('tool_calls', data); }
          else if (type === 'tool_result') { send('tool_result', data); }
        },
      });

      // The reply from agent.run is the final full text (from non-streaming path or last iteration)
      // In streaming mode, fullContent has the accumulated content
      const finalContent = fullContent || reply;

      const userLabel = audio ? `[voice] ${userMessage}` : (message || '(image)');
      history.push({ role: 'user', content: userLabel, ...(images?.length ? { hasImage: true } : {}), ...(audio ? { hasAudio: true } : {}) });
      history.push({ role: 'assistant', content: finalContent, ...(fullThinking ? { thinking: fullThinking } : {}) });
      while (history.length > 40) history.shift();

      await saveSession(sid, {
        messages: history,
        agentId: aid,
        agentName: agentConfig?.name || 'default',
      });

      send('done', { sessionId: sid });
    } catch (err) {
      send('error', { message: err.message });
    }

    res.end();
  });

  // --- Agents CRUD ---
  app.get('/api/agents', authMiddleware, async (req, res) => {
    const result = await query('SELECT * FROM agents ORDER BY id');
    res.json({ agents: result.rows });
  });

  app.post('/api/agents', authMiddleware, async (req, res) => {
    const { name, system_prompt, model, think } = req.body;
    if (!name) return res.status(400).json({ error: 'name required' });
    const result = await query(
      'INSERT INTO agents (name, system_prompt, model, think) VALUES ($1, $2, $3, $4) RETURNING *',
      [name, system_prompt || '', model || config.ollama.model, think || false],
    );
    res.json(result.rows[0]);
  });

  app.put('/api/agents/:id', authMiddleware, async (req, res) => {
    const { name, system_prompt, model, think } = req.body;
    const result = await query(
      'UPDATE agents SET name = COALESCE($1, name), system_prompt = COALESCE($2, system_prompt), model = COALESCE($3, model), think = COALESCE($4, think) WHERE id = $5 RETURNING *',
      [name, system_prompt, model, think, req.params.id],
    );
    if (result.rowCount === 0) return res.status(404).json({ error: 'not found' });
    res.json(result.rows[0]);
  });

  app.delete('/api/agents/:id', authMiddleware, async (req, res) => {
    if (req.params.id === '1') return res.status(400).json({ error: 'cannot delete default agent' });
    await query('DELETE FROM agents WHERE id = $1', [req.params.id]);
    res.json({ ok: true });
  });

  // --- Channels CRUD ---
  app.get('/api/channels', authMiddleware, async (req, res) => {
    const result = await query(`
      SELECT c.*, a.name as agent_name
      FROM channels c JOIN agents a ON c.agent_id = a.id
      ORDER BY c.id
    `);
    res.json({ channels: result.rows });
  });

  app.post('/api/channels', authMiddleware, async (req, res) => {
    const { agent_id, type, name, config: channelConfig, response_mode, response_interval } = req.body;
    if (!agent_id || !type || !name) return res.status(400).json({ error: 'agent_id, type, and name required' });
    const result = await query(
      'INSERT INTO channels (agent_id, type, name, config, response_mode, response_interval) VALUES ($1, $2, $3, $4, $5, $6) RETURNING *',
      [agent_id, type, name, JSON.stringify(channelConfig || {}), response_mode || 'immediate', response_interval],
    );
    res.json(result.rows[0]);
    if (telegramManager) telegramManager.reload().catch(e => console.error('[telegram] reload failed:', e.message));
  });

  app.put('/api/channels/:id', authMiddleware, async (req, res) => {
    const { agent_id, name, config: channelConfig, response_mode, response_interval, enabled } = req.body;
    const result = await query(
      `UPDATE channels SET
        agent_id = COALESCE($1, agent_id), name = COALESCE($2, name),
        config = COALESCE($3, config), response_mode = COALESCE($4, response_mode),
        response_interval = COALESCE($5, response_interval), enabled = COALESCE($6, enabled)
      WHERE id = $7 RETURNING *`,
      [agent_id, name, channelConfig ? JSON.stringify(channelConfig) : null, response_mode, response_interval, enabled, req.params.id],
    );
    if (result.rowCount === 0) return res.status(404).json({ error: 'not found' });
    res.json(result.rows[0]);
    if (telegramManager) telegramManager.reload().catch(e => console.error('[telegram] reload failed:', e.message));
  });

  app.delete('/api/channels/:id', authMiddleware, async (req, res) => {
    await query('DELETE FROM channels WHERE id = $1', [req.params.id]);
    res.json({ ok: true });
    if (telegramManager) telegramManager.reload().catch(e => console.error('[telegram] reload failed:', e.message));
  });

  return app;
}

async function loadSession(id) {
  try {
    const file = join(config.paths.sessions, `${id}.json`);
    return JSON.parse(await readFile(file, 'utf-8'));
  } catch { return { messages: [] }; }
}

async function saveSession(id, data) {
  await mkdir(config.paths.sessions, { recursive: true });
  await writeFile(join(config.paths.sessions, `${id}.json`), JSON.stringify(data), 'utf-8');
}
