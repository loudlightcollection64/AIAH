#!/usr/bin/env node
// Runs the connector against the local demo site as claude-agent (SMA Agent role), issuing its app password via a dev-only route.

import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));
const port = process.env.SMA_LOCAL_PORT || '9400';
const devDir = path.join(here, '..', '..', 'site', 'dev');
const adminFile = path.join(devDir, `.local-credentials-${port}.json`);
const agentFile = path.join(devDir, `.local-credentials-agent-${port}.json`);
const LOCAL = /^http:\/\/(127\.0\.0\.1|localhost)[:/]/;

async function agentCredentials() {
  if (existsSync(agentFile)) {
    const cached = JSON.parse(readFileSync(agentFile, 'utf8'));
    // A fresh seed issues new passwords, so re-issue if the cached one stops working.
    const probe = await fetch(`${cached.site_url}/?rest_route=/wp/v2/sma-events&per_page=1`, {
      headers: { Authorization: `Basic ${Buffer.from(`${cached.username}:${cached.app_password}`).toString('base64')}` },
      signal: AbortSignal.timeout(60000),
    }).catch(() => null);
    if (probe && probe.ok) return cached;
  }
  if (!existsSync(adminFile)) return null;
  const admin = JSON.parse(readFileSync(adminFile, 'utf8'));
  if (!LOCAL.test(admin.site_url)) throw new Error('local.mjs only connects to the local demo site.');
  const res = await fetch(`${admin.site_url}/?rest_route=/sma-dev/v1/test-user`, {
    method: 'POST',
    headers: {
      Authorization: `Basic ${Buffer.from(`${admin.username}:${admin.app_password}`).toString('base64')}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ login: 'claude-agent', role: 'sma_agent' }),
    signal: AbortSignal.timeout(120000),
  });
  if (!res.ok) throw new Error(`Could not set up claude-agent on the local site (HTTP ${res.status}).`);
  const data = await res.json();
  const creds = { site_url: admin.site_url, username: data.login, app_password: data.app_password, role: data.role };
  writeFileSync(agentFile, JSON.stringify(creds, null, 2));
  return creds;
}

try {
  const creds = await agentCredentials();
  if (creds) {
    process.env.SMA_SITE_URL = creds.site_url;
    process.env.SMA_WP_USER = creds.username;
    process.env.SMA_WP_APP_PASSWORD = creds.app_password;
  }
} catch (error) {
  // Start anyway; check_connection then explains what's missing (start the demo first).
  process.stderr.write(`sma-wordpress-local: ${error.message}\n`);
}

await import('./server/index.js');
