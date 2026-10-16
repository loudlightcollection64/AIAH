import { test } from 'node:test';
import assert from 'node:assert/strict';
import { checkFence, checkBody, FenceError } from '../server/wp.js';

const allowed = [
  ['GET', '/wp/v2/pages'],
  ['GET', '/wp/v2/pages?per_page=100&_fields=id,title,link,modified&search=event'],
  ['GET', '/wp/v2/sma-events?per_page=100&status=publish,draft&context=edit'],
  ['GET', '/wp/v2/sma-events/12?context=edit'],
  ['POST', '/wp/v2/sma-events'],
  ['POST', '/wp/v2/sma-events/12'],
];

const refused = [
  // Family data, forms, bookings, email: nothing outside event records.
  ['GET', '/fluentform/v1/submissions'],
  ['GET', '/fluentform/v1/forms/5'],
  ['POST', '/fluentform/v1/forms/5'],
  ['GET', '/fluentform/v1/settings/5'],
  ['GET', '/fluent-crm/v2/subscribers'],
  ['GET', '/fluent-crm/v2/campaigns'],
  ['POST', '/fluent-crm/v2/campaigns/7/schedule'],
  ['GET', '/fluent-crm/v2/tags'],
  ['GET', '/fluent-booking/v2/calendars'],
  ['GET', '/fluent-booking/v2/schedules'],
  ['GET', '/sma/v1/families/stage'],
  ['POST', '/sma/v1/families/approve'],
  ['GET', '/wp/v2/users'],
  ['GET', '/wp/v2/users/me'],
  ['POST', '/batch/v1'],
  ['POST', '/wp-abilities/v1/abilities/fluent-crm/list-contacts/run'],
  ['GET', '/mcp/mcp-adapter-default-server'],
  ['DELETE', '/wp/v2/sma-events/12'],
  ['PUT', '/wp/v2/sma-events/12'],
  ['POST', '/wp/v2/pages'],
  // Query-string tricks: a second rest_route, encoded or bracketed keys, data-pulling options, repeats.
  ['GET', '/wp/v2/sma-events?rest_route=/fluentform/v1/submissions'],
  ['GET', '/wp/v2/pages?per_page=1&rest_route=/fluent-crm/v2/subscribers'],
  ['GET', '/wp/v2/sma-events?wit%68[]=x'],
  ['GET', '/wp/v2/sma-events?with[]=campaign_emails'],
  ['GET', '/wp/v2/sma-events?_embed=1'],
  ['GET', '/wp/v2/sma-events?per_page=1&per_page=2'],
  ['GET', '/wp/v2/pages?author=1'],
  ['POST', '/wp/v2/sma-events?status=publish'],
  // Path tricks: dot segments, doubled or encoded slashes, case, spaces, fragments.
  ['GET', '/wp/v2/sma-events/../../fluentform/v1/submissions'],
  ['GET', '/wp/v2/sma-events/./12'],
  ['GET', '/wp/v2//sma-events'],
  ['GET', '/wp/v2/sma-events%2F..%2F..%2Fusers'],
  ['GET', '/WP/v2/sma-events'],
  ['GET', '/wp/v2/sma-events/12 '],
  ['GET', '/wp/v2/sma-events#x'],
  ['GET', 'wp/v2/sma-events'],
  ['GET', '/wp/v2/sma-events\\..\\users'],
];

for (const [method, route] of allowed) {
  test(`allows ${method} ${route}`, () => assert.doesNotThrow(() => checkFence(method, route)));
}

for (const [method, route] of refused) {
  test(`refuses ${method} ${route}`, () => assert.throws(() => checkFence(method, route), FenceError));
}

const good = {
  title: 'Pittsburgh — November 2026',
  status: 'draft',
  slug: '2026-11-14-pittsburgh',
  meta: { city: 'Pittsburgh', event_date: '2026-11-14', capacity: 20 },
};

test('event body: details pass', () => assert.doesNotThrow(() => checkBody('POST', '/wp/v2/sma-events', good)));
test('event body: publish passes', () =>
  assert.doesNotThrow(() => checkBody('POST', '/wp/v2/sma-events/3', { ...good, status: 'publish' })));
test('event body: other statuses refused', () =>
  assert.throws(() => checkBody('POST', '/wp/v2/sma-events/3', { ...good, status: 'private' }), FenceError));
test('event body: site-owned fields refused', () => {
  for (const f of ['booking_url', 'booking_session_id', 'booking_calendar_id', 'booking_page_id', 'crm_tag_id', 'sma_provision_error', 'sma_provisioned_at']) {
    assert.throws(() => checkBody('POST', '/wp/v2/sma-events', { ...good, meta: { [f]: 'x' } }), FenceError, f);
  }
});
test('event body: other post fields refused', () => {
  for (const f of ['author', 'password', 'content', 'template', 'date']) {
    assert.throws(() => checkBody('POST', '/wp/v2/sma-events', { ...good, [f]: 'x' }), FenceError, f);
  }
});
test('event body: must be an object', () => {
  assert.throws(() => checkBody('POST', '/wp/v2/sma-events', null), FenceError);
  assert.throws(() => checkBody('POST', '/wp/v2/sma-events', []), FenceError);
  assert.throws(() => checkBody('POST', '/wp/v2/sma-events', { meta: [] }), FenceError);
});
