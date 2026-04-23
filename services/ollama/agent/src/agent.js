import { chat, chatStream } from './llm.js';
import config from './config.js';
import { transcribeAudio } from './audio.js';

const MAX_ITERATIONS = 10;

const DEFAULT_SYSTEM_PROMPT = `You are DogeClaw, a personal AI agent running inside a Docker container.
Be concise and practical.`;

export class Agent {
  #registry;

  constructor(registry) {
    this.#registry = registry;
  }

  #buildSystemPrompt(customPrompt) {
    const base = customPrompt || DEFAULT_SYSTEM_PROMPT;

    const toolDescriptions = this.#registry.getDefinitions().map(t => {
      const fn = t.function;
      const params = fn.parameters?.properties
        ? Object.keys(fn.parameters.properties).join(', ')
        : '';
      return `- ${fn.name}(${params}): ${fn.description}`;
    }).join('\n');

    return `${base}

Current date: ${new Date().toISOString().split('T')[0]}
Workspace: ${config.paths.files}

You have the following tools available. Use them whenever needed — do not say you lack capabilities:
${toolDescriptions}

IMPORTANT rules for tool use:
- Always prefer ACTION over asking the user. If you can do it with your tools, do it.
- Chain tools together autonomously. For example: use web_search to find URLs, then use web_fetch on those URLs to read their content, then summarize. Do NOT ask the user to pick URLs or confirm steps.
- Never say "I cannot" when you have a tool that can do it. Just use the tool.
- When asked to research something, search the web, visit multiple result pages, and synthesize the information yourself.
- You can call tools multiple times in sequence. Do not stop after one tool call if more are needed to complete the task.`;
  }

  /**
   * Run the agent loop.
   * @param {string} userMessage
   * @param {Array} history
   * @param {object} opts
   *   modelConfig: { base_url, model_id, think, accepts }  (from models table)
   *   systemPrompt, onEvent, systemNote, images, audio, audioMime
   */
  async run(userMessage, history = [], opts = {}) {
    const systemPrompt = this.#buildSystemPrompt(opts.systemPrompt);
    const mc = opts.modelConfig || {};
    const baseUrl = mc.base_url || config.ollama.url;
    const model = mc.model_id || config.ollama.model;
    const think = mc.think ?? config.ollama.think;
    const accepts = mc.accepts || ['text'];
    const onEvent = opts.onEvent || null;

    // Handle audio: transcribe or forward depending on model capabilities
    let processedMessage = userMessage;
    if (opts.audio) {
      if (accepts.includes('audio')) {
        // Model accepts audio natively — this is a placeholder for when Ollama supports it
        processedMessage = processedMessage || 'What is said in this audio?';
        // TODO: pass audio to Ollama when API supports it
      } else {
        // Transcribe with whisper
        if (onEvent) onEvent('status', 'Transcribing audio...');
        const transcript = await transcribeAudio(opts.audio, opts.audioMime);
        if (onEvent) onEvent('transcript', transcript);
        processedMessage = processedMessage
          ? `${processedMessage}\n\n[Voice message]: ${transcript}`
          : transcript;
      }
    }

    if (!processedMessage && opts.images?.length) {
      processedMessage = 'What do you see in this image?';
    }

    const systemContent = opts.systemNote
      ? `${systemPrompt}\n\nNote: ${opts.systemNote}`
      : systemPrompt;

    const userMsg = { role: 'user', content: processedMessage };
    if (opts.images?.length && accepts.includes('image')) {
      userMsg.images = opts.images;
    }

    const messages = [
      { role: 'system', content: systemContent },
      ...history,
      userMsg,
    ];

    const tools = this.#registry.getDefinitions();
    const llmOpts = { baseUrl, model, think };

    for (let i = 0; i < MAX_ITERATIONS; i++) {
      let response;

      if (onEvent) {
        response = await chatStream(messages, tools, llmOpts, onEvent);
      } else {
        response = await chat(messages, tools, llmOpts);
      }

      if (!response.tool_calls || response.tool_calls.length === 0) {
        return response.content || '(no response)';
      }

      messages.push(response);
      if (onEvent) onEvent('tool_calls', response.tool_calls);

      for (const call of response.tool_calls) {
        const result = await this.#registry.execute(
          call.function.name,
          call.function.arguments,
        );
        if (onEvent) onEvent('tool_result', { name: call.function.name, result });
        messages.push({ role: 'tool', content: JSON.stringify(result) });
      }
    }

    return '(reached maximum tool call iterations)';
  }
}
