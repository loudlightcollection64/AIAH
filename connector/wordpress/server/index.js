#!/usr/bin/env node
// So Many Angels WordPress MCP server: reads site pages and reads or saves events; wp.js fences every request.

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';
import { wp, configProblem, FenceError } from './wp.js';
import * as events from './events.js';

const server = new McpServer({ name: 'sma-wordpress', version: '0.2.0' });

function ok(value) {
  return { content: [{ type: 'text', text: typeof value === 'string' ? value : JSON.stringify(value, null, 2) }] };
}

function fail(error) {
  const prefix = error instanceof FenceError ? '' : 'Something went wrong: ';
  return { content: [{ type: 'text', text: prefix + error.message }], isError: true };
}

function tool(name, config, handler) {
  server.registerTool(name, config, async (args) => {
    try {
      return ok(await handler(args || {}));
    } catch (error) {
      return fail(error);
    }
  });
}

tool(
  'check_connection',
  {
    title: 'Check connection',
    description: 'Confirm the connector can reach the So Many Angels website. Run this first.',
  },
  async () => {
    const problem = configProblem();
    if (problem) return problem;
    const result = {};
    for (const [label, route] of [
      ['pages', '/wp/v2/pages?per_page=1&_fields=id'],
      ['events', '/wp/v2/sma-events?per_page=1&context=edit&_fields=id'],
    ]) {
      try {
        await wp('GET', route);
        result[label] = 'ok';
      } catch (error) {
        result[label] = error.message;
      }
    }
    return result;
  }
);

tool(
  'list_pages',
  {
    title: 'List website pages',
    description: 'List published pages on the website with their link. Optionally filter by a search word.',
    inputSchema: { search: z.string().optional().describe('Word to look for in page titles, e.g. "event"') },
  },
  async ({ search }) => {
    const query = new URLSearchParams({ per_page: '100', _fields: 'id,title,link,modified' });
    if (search) query.set('search', search);
    const pages = await wp('GET', `/wp/v2/pages?${query}`);
    return pages.map((p) => ({ id: p.id, title: p.title?.rendered, link: p.link, modified: p.modified }));
  }
);

events.register(tool, wp);

const transport = new StdioServerTransport();
await server.connect(transport);
