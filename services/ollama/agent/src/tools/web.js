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
  $('script, style, nav, footer, header, iframe, noscript, svg').remove();

  if (selector) {
    const el = $(selector);
    return el.text().replace(/\s+/g, ' ').trim();
  }

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

async function searchDDG(query, limit = 8) {
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
      let realUrl = href;
      try {
        const parsed = new URL(href, 'https://duckduckgo.com');
        realUrl = parsed.searchParams.get('uddg') || href;
      } catch {}
      results.push({ title, url: realUrl, snippet });
    }
  });

  return results;
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
    const results = await searchDDG(query, Math.min(max_results || 8, 20));
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
          selector: { type: 'string', description: 'CSS selector to extract specific content (optional)' },
          depth: { type: 'number', description: 'How many levels of links to follow (0-2). Default 0.' },
          max_pages: { type: 'number', description: 'Max total pages when depth > 0 (default 5, max 15)' },
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
        const page = { url: finalUrl, status, text: text.slice(0, 8000) };

        if (currentDepth < maxDepth) {
          const links = extractLinks(html, finalUrl);
          page.links = links.slice(0, 30);
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

  // --- web_research ---
  registry.register('web_research', {
    type: 'function',
    function: {
      name: 'web_research',
      description: 'Research a topic: searches the web, visits the top result pages, and returns combined content from all of them. This is the best tool for answering questions that need up-to-date information. Use this instead of web_search when you need actual page content, not just links. Examples: "latest news on Syria", "how to install Docker on Ubuntu", "Tesla stock price today", "current weather in Berlin".',
      parameters: {
        type: 'object',
        properties: {
          query: { type: 'string', description: 'What to research' },
          num_sites: { type: 'number', description: 'How many sites to visit (default 3, max 5)' },
        },
        required: ['query'],
      },
    },
  }, async ({ query, num_sites }) => {
    const sitesToVisit = Math.min(num_sites || 3, 5);

    // Step 1: Search
    const searchResults = await searchDDG(query, sitesToVisit + 3); // fetch a few extra in case some fail

    // Step 2: Fetch top results in parallel
    const fetches = searchResults.slice(0, sitesToVisit + 2).map(async (result) => {
      try {
        const { html } = await fetchPage(result.url, 12000);
        const text = extractText(html);
        if (text.length < 50) return null; // skip empty pages
        return {
          title: result.title,
          url: result.url,
          content: text.slice(0, 4000),
        };
      } catch {
        return null;
      }
    });

    const pages = (await Promise.all(fetches)).filter(Boolean).slice(0, sitesToVisit);

    // Step 3: Build combined report
    const report = pages.map((p, i) =>
      `--- Source ${i + 1}: ${p.title} (${p.url}) ---\n${p.content}`
    ).join('\n\n');

    return {
      query,
      sources: pages.map(p => ({ title: p.title, url: p.url })),
      content: report || '(no content could be extracted from search results)',
    };
  });
}
