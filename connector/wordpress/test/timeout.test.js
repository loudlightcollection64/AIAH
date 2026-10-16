// A site that accepts the connection but never answers must produce a plain error, not a hang.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';

test('a hung site fails with a plain timeout message', async () => {
  const sockets = [];
  const server = http.createServer(() => {}); // never responds
  server.on('connection', (s) => sockets.push(s));
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  try {
    const { port } = server.address();
    process.env.SMA_SITE_URL = `http://127.0.0.1:${port}`;
    process.env.SMA_WP_USER = 'test';
    process.env.SMA_WP_APP_PASSWORD = 'test';
    process.env.SMA_HTTP_TIMEOUT_MS = '300';
    const { wp } = await import(`../server/wp.js?timeout-test`);

    const started = Date.now();
    await assert.rejects(wp('GET', '/wp/v2/pages?per_page=1'), /did not answer within/);
    assert.ok(Date.now() - started < 5000, 'gave up promptly');
  } finally {
    sockets.forEach((s) => s.destroy());
    server.close();
  }
});
