// End-to-end check against the seeded local site: publishes an event via local.mjs, then proves claude-agent is refused on family-data routes.

import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';

const here = path.dirname(fileURLToPath(import.meta.url));
const kit = path.resolve(here, '..', '..', '..');
const port = process.env.SMA_LOCAL_PORT || '9400';

const transport = new StdioClientTransport({
  command: process.execPath,
  args: ['connector/wordpress/local.mjs'],
  cwd: kit,
  env: { ...process.env, SMA_LOCAL_PORT: port },
  stderr: 'inherit',
});
const client = new Client({ name: 'smoke', version: '0.0.0' });
await client.connect(transport);

const failures = [];
function expect(ok, what) {
  console.log(`${ok ? 'PASS' : 'FAIL'} ${what}`);
  if (!ok) failures.push(what);
}

async function call(name, args = {}) {
  // The local Playground copy takes several seconds per request.
  const result = await client.callTool({ name, arguments: args }, undefined, { timeout: 600000 });
  const text = result.content.map((c) => c.text).join('\n');
  let data = text;
  try {
    data = JSON.parse(text);
  } catch {
    // plain text
  }
  return { error: Boolean(result.isError), data, text };
}

const tools = (await client.listTools()).tools.map((t) => t.name).sort();
expect(
  JSON.stringify(tools) === JSON.stringify(['check_connection', 'get_event', 'list_events', 'list_pages', 'save_event']),
  `tool list is ${tools.join(', ')}`
);

const conn = await call('check_connection');
expect(!conn.error && conn.data.pages === 'ok' && conn.data.events === 'ok', 'check_connection: pages and events ok');

const pages = await call('list_pages');
expect(!pages.error && Array.isArray(pages.data), 'list_pages works');

const before = await call('list_events');
expect(!before.error && Array.isArray(before.data), `list_events works (${before.data.length} events)`);

// A new event, a day nobody else uses, about two months out.
const date = new Date(Date.now() + 61 * 864e5).toISOString().slice(0, 10);
const details = {
  city: 'Smoketown',
  venue: 'Demo Hall (fictional)',
  address: '1 Example Way, Smoketown, PA 15000',
  event_date: date,
  start_time: '10:00',
  end_time: '12:00',
  capacity: 8,
  canvas_handover: 'Shipped to the family within 60 days',
};

const preview = await call('save_event', details);
expect(!preview.error && preview.data.saved === false, 'save_event previews without saving');

const draft = await call('save_event', { ...details, apply: true });
expect(!draft.error && draft.data.saved === true && draft.data.status === 'draft', `save_event creates a draft (${draft.text.slice(0, 120)})`);
const id = draft.data.id;

const bad = await call('save_event', { ...details, id, end_time: '09:00', apply: true });
expect(bad.error, 'save_event refuses an end time before the start');

const published = await call('save_event', { ...details, id, publish: true, apply: true });
expect(!published.error && published.data.status === 'publish', 'save_event publishes as claude-agent');

// The site sets up booking when the event is published (plugin, server-side).
let ready = null;
for (let i = 0; i < 6 && !ready; i++) {
  const got = await call('get_event', { id });
  if (!got.error && (got.data.booking_url || got.data.sma_provision_error)) ready = got.data;
  else await new Promise((r) => setTimeout(r, 5000));
}
expect(Boolean(ready && ready.booking_url), `site set up booking (booking_url ${ready?.booking_url || 'missing'})`);
expect(!ready?.sma_provision_error, `no set-up problem${ready?.sma_provision_error ? `: ${ready.sma_provision_error}` : ''}`);

await client.close();

// Least privilege, outside the connector: claude-agent's password on family-data routes.
const agent = JSON.parse(readFileSync(path.join(kit, 'site', 'dev', `.local-credentials-agent-${port}.json`), 'utf8'));
const auth = `Basic ${Buffer.from(`${agent.username}:${agent.app_password}`).toString('base64')}`;
const familyRoutes = [
  '/fluentform/v1/submissions',
  '/fluentform/v1/forms',
  '/fluent-crm/v2/subscribers',
  '/fluent-crm/v2/campaigns',
  '/fluent-crm/v2/tags',
  '/fluent-booking/v2/calendars',
  '/fluent-booking/v2/schedules',
  // The plain user list is public on WordPress; full records need context=edit, which claude-agent must not get.
  '/wp/v2/users&context=edit',
  '/sma/v1/events/' + (ready ? encodeURIComponent(published.data.slug || '') : 'x') + '/counts',
];
for (const route of familyRoutes) {
  const res = await fetch(`${agent.site_url}/?rest_route=${route}&_=1`, {
    headers: { Authorization: auth },
    signal: AbortSignal.timeout(120000),
  });
  expect(res.status === 401 || res.status === 403, `claude-agent refused on ${route} (HTTP ${res.status})`);
}

console.log(failures.length ? `\nFAILED (${failures.length}):\n  ${failures.join('\n  ')}` : '\nALL CHECKS PASSED');
process.exitCode = failures.length ? 1 : 0;
