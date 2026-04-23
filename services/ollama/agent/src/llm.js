import config from './config.js';

function isOpenRouter(opts) {
  return opts.provider === 'openrouter';
}

function buildOpenRouterMessages(messages) {
  return messages.map(m => {
    if (m.role === 'system' || m.role === 'user' || m.role === 'assistant' || m.role === 'tool') {
      const msg = { role: m.role, content: m.content || '' };
      if (m.tool_calls) msg.tool_calls = m.tool_calls;
      if (m.role === 'tool' && m.tool_call_id) msg.tool_call_id = m.tool_call_id;
      return msg;
    }
    return m;
  });
}

function buildOpenRouterTools(tools) {
  return tools.map(t => ({ type: 'function', function: t.function }));
}

// --- Ollama ---

export async function chat(messages, tools = [], opts = {}) {
  if (isOpenRouter(opts)) return chatOpenRouter(messages, tools, opts);

  const baseUrl = opts.baseUrl || config.ollama.url;
  const model = opts.model || config.ollama.model;
  const think = opts.think ?? config.ollama.think;

  const body = { model, messages, stream: false, think };
  if (tools.length > 0) body.tools = tools;

  const res = await fetch(`${baseUrl}/api/chat`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });

  if (!res.ok) {
    const text = await res.text();
    throw new Error(`Ollama API error ${res.status}: ${text}`);
  }

  const data = await res.json();
  return data.message;
}

export async function chatStream(messages, tools = [], opts = {}, onEvent) {
  if (isOpenRouter(opts)) return chatStreamOpenRouter(messages, tools, opts, onEvent);

  const baseUrl = opts.baseUrl || config.ollama.url;
  const model = opts.model || config.ollama.model;
  const think = opts.think ?? config.ollama.think;

  const body = { model, messages, stream: true, think };
  if (tools.length > 0) body.tools = tools;

  const res = await fetch(`${baseUrl}/api/chat`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });

  if (!res.ok) {
    const text = await res.text();
    throw new Error(`Ollama API error ${res.status}: ${text}`);
  }

  let fullContent = '';
  let fullThinking = '';
  let toolCalls = null;

  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';

  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    buffer += decoder.decode(value, { stream: true });
    const lines = buffer.split('\n');
    buffer = lines.pop();

    for (const line of lines) {
      if (!line.trim()) continue;
      let chunk;
      try { chunk = JSON.parse(line); } catch { continue; }
      if (chunk.message?.thinking) { fullThinking += chunk.message.thinking; if (onEvent) onEvent('thinking', chunk.message.thinking); }
      if (chunk.message?.content) { fullContent += chunk.message.content; if (onEvent) onEvent('content', chunk.message.content); }
      if (chunk.message?.tool_calls) { toolCalls = chunk.message.tool_calls; }
    }
  }

  return { role: 'assistant', content: fullContent, thinking: fullThinking, tool_calls: toolCalls || undefined };
}

// --- OpenRouter (OpenAI-compatible) ---

async function chatOpenRouter(messages, tools, opts) {
  const body = {
    model: opts.model,
    messages: buildOpenRouterMessages(messages),
    stream: false,
  };
  if (tools.length > 0) body.tools = buildOpenRouterTools(tools);

  const res = await fetch(`${opts.baseUrl}/api/v1/chat/completions`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${opts.apiKey}`,
      'HTTP-Referer': 'https://dogeclaw.beshtawi.online',
    },
    body: JSON.stringify(body),
  });

  if (!res.ok) {
    const text = await res.text();
    throw new Error(`OpenRouter API error ${res.status}: ${text}`);
  }

  const data = await res.json();
  const choice = data.choices?.[0]?.message;
  return {
    role: 'assistant',
    content: choice?.content || '',
    tool_calls: choice?.tool_calls?.map(tc => ({
      function: { name: tc.function.name, arguments: JSON.parse(tc.function.arguments || '{}') },
    })) || undefined,
  };
}

async function chatStreamOpenRouter(messages, tools, opts, onEvent) {
  const body = {
    model: opts.model,
    messages: buildOpenRouterMessages(messages),
    stream: true,
  };
  if (tools.length > 0) body.tools = buildOpenRouterTools(tools);

  const res = await fetch(`${opts.baseUrl}/api/v1/chat/completions`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${opts.apiKey}`,
      'HTTP-Referer': 'https://dogeclaw.beshtawi.online',
    },
    body: JSON.stringify(body),
  });

  if (!res.ok) {
    const text = await res.text();
    throw new Error(`OpenRouter API error ${res.status}: ${text}`);
  }

  let fullContent = '';
  let toolCalls = [];
  let currentToolIdx = -1;

  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';

  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    buffer += decoder.decode(value, { stream: true });
    const lines = buffer.split('\n');
    buffer = lines.pop();

    for (const line of lines) {
      if (!line.startsWith('data: ') || line === 'data: [DONE]') continue;
      let chunk;
      try { chunk = JSON.parse(line.slice(6)); } catch { continue; }
      const delta = chunk.choices?.[0]?.delta;
      if (!delta) continue;

      if (delta.content) {
        fullContent += delta.content;
        if (onEvent) onEvent('content', delta.content);
      }
      if (delta.tool_calls) {
        for (const tc of delta.tool_calls) {
          if (tc.index !== undefined && tc.index !== currentToolIdx) {
            currentToolIdx = tc.index;
            toolCalls.push({ function: { name: '', arguments: '' } });
          }
          const current = toolCalls[toolCalls.length - 1];
          if (tc.function?.name) current.function.name += tc.function.name;
          if (tc.function?.arguments) current.function.arguments += tc.function.arguments;
        }
      }
    }
  }

  // Parse accumulated tool call arguments
  const parsedToolCalls = toolCalls.length > 0 ? toolCalls.map(tc => ({
    function: { name: tc.function.name, arguments: JSON.parse(tc.function.arguments || '{}') },
  })) : undefined;

  return { role: 'assistant', content: fullContent, tool_calls: parsedToolCalls };
}
