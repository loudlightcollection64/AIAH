#!/usr/bin/env node
// Runs the connector against the live site, reading the claude-agent app password from the macOS Keychain.

import { execFileSync } from 'node:child_process';

const site = process.env.SMA_SITE_URL || 'https://somanyangels.org';
const user = process.env.SMA_WP_USER || 'claude-agent';

if (!/^https:\/\//.test(site)) {
  throw new Error('live.mjs only connects over https.');
}

let password = '';
try {
  password = execFileSync('security', ['find-generic-password', '-s', 'sma-wordpress', '-a', user, '-w'], {
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'ignore'],
  }).trim();
} catch {
  process.stderr.write(
    'sma-wordpress-live: no password in the Keychain. Run: security add-generic-password -s sma-wordpress -a claude-agent -w\n'
  );
}

process.env.SMA_SITE_URL = site;
process.env.SMA_WP_USER = user;
process.env.SMA_WP_APP_PASSWORD = password;

await import('./server/index.js');
