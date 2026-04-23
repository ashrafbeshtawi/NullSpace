import * as cheerio from 'cheerio';

const USER_AGENT = 'Mozilla/5.0 (compatible; DogeClaw/1.0)';

async function fetchPage(url, timeout = 15000) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeout);
  try {
    const res = await fetch(url, {
      headers: { 'User-Agent': USER_AGENT },
      signal: controller.signal,
      redirect: 'follow',
    });
    const html = await res.text();
    return { html, status: res.status, url: res.url };
  } finally {
    clearTimeout(timer);
  }
}

function extractText(html, selector) {
  const $ = cheerio.load(html);
  // Remove noise
  $('script, style, nav, footer, header, iframe, noscript, svg').remove();

  if (selector) {
    const el = $(selector);
    return el.text().replace(/\s+/g, ' ').trim();
  }

  // Try common content selectors
  const content = $('article, main, [role="main"], .content, .post-content, .entry-content').first();
  const text = (content.length ? content : $('body')).text().replace(/\s+/g, ' ').trim();
  return text;
}

function extractLinks(html, baseUrl) {
  const $ = cheerio.load(html);
  const links = [];
  const seen = new Set();
  $('a[href]').each((_, el) => {
    try {
      const href = new URL($(el).attr('href'), baseUrl).href;
      if (!seen.has(href) && href.startsWith('http')) {
        seen.add(href);
        const label = $(el).text().replace(/\s+/g, ' ').trim().slice(0, 80);
        links.push({ url: href, text: label || href });
      }
    } catch {}
  });
  return links;
}

export function register(registry) {
  // --- web_search ---
  registry.register('web_search', {
    type: 'function',
    function: {
      name: 'web_search',
      description: 'Search the web using DuckDuckGo and return results with titles, URLs, and snippets. Use this to find information, lookup facts, find documentation, etc. Examples: "javascript fetch API", "weather Berlin", "latest news on AI".',
      parameters: {
        type: 'object',
        properties: {
          query: { type: 'string', description: 'Search query' },
          max_results: { type: 'number', description: 'Max results to return (default 8, max 20)' },
        },
        required: ['query'],
      },
    },
  }, async ({ query, max_results }) => {
    const limit = Math.min(max_results || 8, 20);
    const url = `https://html.duckduckgo.com/html/?q=${encodeURIComponent(query)}`;

    const { html } = await fetchPage(url);
    const $ = cheerio.load(html);
    const results = [];

    $('div.result').each((i, el) => {
      if (results.length >= limit) return false;
      const title = $(el).find('a.result__a').text().trim();
      const href = $(el).find('a.result__a').attr('href');
      const snippet = $(el).find('.result__snippet').text().trim();
      if (title && href) {
        // DuckDuckGo wraps URLs in a redirect — extract the real one
        let realUrl = href;
        try {
          const parsed = new URL(href, 'https://duckduckgo.com');
          realUrl = parsed.searchParams.get('uddg') || href;
        } catch {}
        results.push({ title, url: realUrl, snippet });
      }
    });

    return { query, results };
  });

  // --- web_fetch ---
  registry.register('web_fetch', {
    type: 'function',
    function: {
      name: 'web_fetch',
      description: 'Fetch a URL and extract its text content. Optionally follow links to crawl multiple pages. Use this to read articles, documentation, API responses, or any webpage. Examples: fetch "https://example.com" to read it, fetch with depth=1 to also follow links on the page.',
      parameters: {
        type: 'object',
        properties: {
          url: { type: 'string', description: 'URL to fetch' },
          selector: { type: 'string', description: 'CSS selector to extract specific content (optional, e.g. "article", ".main-content", "#readme")' },
          depth: { type: 'number', description: 'How many levels of links to follow (0=just this page, 1=follow links on this page, max 2). Default 0.' },
          max_pages: { type: 'number', description: 'Max total pages to fetch when depth > 0 (default 5, max 15)' },
        },
        required: ['url'],
      },
    },
  }, async ({ url, selector, depth, max_pages }) => {
    const maxDepth = Math.min(depth || 0, 2);
    const maxPages = Math.min(max_pages || 5, 15);
    const visited = new Set();
    const pages = [];

    async function crawl(pageUrl, currentDepth) {
      if (visited.has(pageUrl) || pages.length >= maxPages) return;
      visited.add(pageUrl);

      try {
        const { html, status, url: finalUrl } = await fetchPage(pageUrl);
        const text = extractText(html, selector);

        // Truncate text to avoid huge payloads
        const truncated = text.slice(0, 8000);
        const page = { url: finalUrl, status, text: truncated };

        if (currentDepth < maxDepth) {
          const links = extractLinks(html, finalUrl);
          page.links = links.slice(0, 30);

          // Follow links at next depth
          for (const link of links) {
            if (pages.length >= maxPages) break;
            await crawl(link.url, currentDepth + 1);
          }
        }

        pages.push(page);
      } catch (err) {
        pages.push({ url: pageUrl, error: err.message });
      }
    }

    await crawl(url, 0);
    return { pages };
  });
}
