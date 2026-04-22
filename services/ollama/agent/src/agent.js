import { chat, chatStream } from './llm.js';
import config from './config.js';

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

    // Always list available tools so the model knows what it can do
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
${toolDescriptions}`;
  }

  /**
   * Run the agent loop.
   * @param {string} userMessage
   * @param {Array} history
   * @param {object} opts - { systemPrompt, model, think, onEvent, systemNote, images }
   */
  async run(userMessage, history = [], opts = {}) {
    const systemPrompt = this.#buildSystemPrompt(opts.systemPrompt);
    const model = opts.model || config.ollama.model;
    const think = opts.think ?? config.ollama.think;
    const onEvent = opts.onEvent || null;

    const systemContent = opts.systemNote
      ? `${systemPrompt}\n\nNote: ${opts.systemNote}`
      : systemPrompt;

    const userMsg = { role: 'user', content: userMessage };
    if (opts.images?.length) {
      userMsg.images = opts.images;
    }

    const messages = [
      { role: 'system', content: systemContent },
      ...history,
      userMsg,
    ];

    const tools = this.#registry.getDefinitions();
    const llmOpts = { model, think };

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

      // Append assistant message with tool calls
      messages.push(response);
      if (onEvent) onEvent('tool_calls', response.tool_calls);

      // Execute each tool call
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
