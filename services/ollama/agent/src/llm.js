import config from './config.js';

export async function chat(messages, tools = [], opts = {}) {
  const model = opts.model || config.ollama.model;
  const think = opts.think ?? config.ollama.think;

  const body = { model, messages, stream: false, think };
  if (tools.length > 0) body.tools = tools;

  const res = await fetch(`${config.ollama.url}/api/chat`, {
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
  const model = opts.model || config.ollama.model;
  const think = opts.think ?? config.ollama.think;

  const body = { model, messages, stream: true, think };
  if (tools.length > 0) body.tools = tools;

  const res = await fetch(`${config.ollama.url}/api/chat`, {
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
    buffer = lines.pop(); // keep incomplete line

    for (const line of lines) {
      if (!line.trim()) continue;
      let chunk;
      try { chunk = JSON.parse(line); } catch { continue; }

      if (chunk.message?.thinking) {
        fullThinking += chunk.message.thinking;
        if (onEvent) onEvent('thinking', chunk.message.thinking);
      }
      if (chunk.message?.content) {
        fullContent += chunk.message.content;
        if (onEvent) onEvent('content', chunk.message.content);
      }
      if (chunk.message?.tool_calls) {
        toolCalls = chunk.message.tool_calls;
      }
    }
  }

  return {
    role: 'assistant',
    content: fullContent,
    thinking: fullThinking,
    tool_calls: toolCalls || undefined,
  };
}
