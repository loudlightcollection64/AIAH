// Fenced WordPress REST client: only the routes in ALLOWED pass checkFence and checkBody; everything else is refused.

const SITE_URL = (process.env.SMA_SITE_URL || '').replace(/\/+$/, '');
const USERNAME = process.env.SMA_WP_USER || '';
const APP_PASSWORD = process.env.SMA_WP_APP_PASSWORD || '';
// A request that hangs (site down, PHP worker stuck) should fail in plain words, not freeze Claude.
const TIMEOUT_MS = Number(process.env.SMA_HTTP_TIMEOUT_MS) || 90000;

// Event fields Claude may write. Booking and CRM fields are set by the site, never by Claude.
export const EVENT_FIELDS = [
  'city', 'venue', 'address', 'event_date', 'start_time', 'end_time', 'timezone', 'capacity', 'canvas_handover',
];

const LIST_QUERY = ['per_page', 'page', 'search', '_fields', 'orderby', 'order'];
const ALLOWED = [
  { method: 'GET', path: /^\/wp\/v2\/pages$/, query: LIST_QUERY },
  { method: 'GET', path: /^\/wp\/v2\/sma-events$/, query: [...LIST_QUERY, 'status', 'context'] },
  { method: 'GET', path: /^\/wp\/v2\/sma-events\/\d+$/, query: ['context', '_fields'] },
  { method: 'POST', path: /^\/wp\/v2\/sma-events$/, query: [] },
  { method: 'POST', path: /^\/wp\/v2\/sma-events\/\d+$/, query: [] },
];

export class FenceError extends Error {}

function refuse(why) {
  throw new FenceError(`Not allowed: ${why}. This connector only reads pages and reads or saves event records.`);
}

export function checkFence(method, route) {
  if (typeof route !== 'string' || !route.startsWith('/')) refuse('malformed route');
  const q = route.indexOf('?');
  const path = q === -1 ? route : route.slice(0, q);
  const query = q === -1 ? '' : route.slice(q + 1);

  // Paths are plain: no encoding, dot segments, doubled slashes, backslashes or fragments.
  if (/[%\\#\s]|\/\/|\/\.{1,2}(\/|$)/.test(path)) refuse(`unusual path ${path}`);

  const rule = ALLOWED.find((r) => r.method === method && r.path.test(path));
  if (!rule) refuse(`${method} ${path}`);

  // Query keys must be plain, allowed and unique (blocks a second rest_route, encoded keys and _embed).
  const seen = new Set();
  for (const pair of query ? query.split('&') : []) {
    if (pair === '') continue;
    const key = pair.split('=')[0];
    if (!/^[a-z_]+$/.test(key)) refuse(`query key "${key}"`);
    if (!rule.query.includes(key)) refuse(`query option "${key}" on ${path}`);
    if (seen.has(key)) refuse(`repeated query option "${key}"`);
    seen.add(key);
  }
}

// What may be sent when saving an event.
export function checkBody(method, route, body) {
  if (method === 'GET') return;
  if (!body || typeof body !== 'object' || Array.isArray(body)) throw new FenceError('Not allowed: event data must be an object.');
  const extra = Object.keys(body).filter((k) => !['title', 'status', 'meta', 'slug'].includes(k));
  if (extra.length) throw new FenceError(`Not allowed: event fields ${extra.join(', ')}.`);
  if ('status' in body && !['draft', 'publish'].includes(body.status)) {
    throw new FenceError('Not allowed: an event is either draft or publish.');
  }
  if ('meta' in body) {
    if (!body.meta || typeof body.meta !== 'object' || Array.isArray(body.meta)) throw new FenceError('Not allowed: meta must be an object.');
    const bad = Object.keys(body.meta).filter((k) => !EVENT_FIELDS.includes(k));
    if (bad.length) throw new FenceError(`Not allowed: Claude can't set ${bad.join(', ')}; the site sets those.`);
  }
}

export function configProblem() {
  const missing = [];
  if (!SITE_URL) missing.push('site address');
  if (!USERNAME) missing.push('WordPress username');
  if (!APP_PASSWORD) missing.push('application password');
  return missing.length ? `The connector is missing: ${missing.join(', ')}. Add them in Claude's connector settings.` : null;
}

export async function wp(method, route, body) {
  checkFence(method, route);
  checkBody(method, route, body);
  const problem = configProblem();
  if (problem) throw new Error(problem);

  const q = route.indexOf('?');
  const path = q === -1 ? route : route.slice(0, q);
  const query = q === -1 ? '' : route.slice(q + 1);
  const url = `${SITE_URL}/?rest_route=${path}${query ? `&${query}` : ''}`;
  const auth = Buffer.from(`${USERNAME}:${APP_PASSWORD.replace(/\s+/g, '')}`).toString('base64');

  let res;
  try {
    res = await fetch(url, {
      method,
      headers: {
        Authorization: `Basic ${auth}`,
        Accept: 'application/json',
        ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
      },
      body: body !== undefined ? JSON.stringify(body) : undefined,
      signal: AbortSignal.timeout(TIMEOUT_MS),
      redirect: 'error',
    });
  } catch (error) {
    if (error?.name === 'TimeoutError') {
      throw new Error(
        `The website did not answer within ${Math.round(TIMEOUT_MS / 1000)} seconds (${method} ${path}). ` +
          'It may be down or very slow. Nothing was confirmed as changed; check before retrying.'
      );
    }
    throw new Error(`Could not reach the website (${method} ${path}): ${error?.message || error}`);
  }

  const text = await res.text();
  let data;
  try {
    data = text ? JSON.parse(text) : null;
  } catch {
    data = text;
  }
  if (!res.ok) {
    const message = (data && typeof data === 'object' && (data.message || data.code)) || String(text).slice(0, 300);
    throw new Error(`WordPress returned ${res.status} for ${method} ${path}: ${message}`);
  }
  return data;
}
