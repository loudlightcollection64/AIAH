// Event tools (post type sma_event); publishing makes the site plugin set up booking, so booking and CRM fields are read-only here.

import { z } from 'zod';
import { DATE, TIME } from './dates.js';
import { EVENT_FIELDS } from './wp.js';

// Set by the site when an event is published.
const SITE_FIELDS = [
  'booking_calendar_id', 'booking_session_id', 'booking_page_id', 'booking_url', 'crm_tag_id',
  'sma_provision_error', 'sma_provisioned_at',
];

export function summarise(e) {
  const meta = e.meta || {};
  const out = {
    id: e.id,
    slug: e.slug,
    title: e.title?.raw ?? e.title?.rendered,
    status: e.status,
    link: e.link,
  };
  for (const f of [...EVENT_FIELDS, ...SITE_FIELDS]) if (f in meta) out[f] = meta[f];
  if (e.status === 'publish') {
    out.setup = meta.sma_provision_error
      ? `Problem: ${meta.sma_provision_error}`
      : meta.booking_url
        ? 'Ready: families can sign up and book'
        : 'Not set up yet (reload in a minute; if it stays like this, check the event in WordPress)';
  }
  return out;
}

function slugFor(city, date) {
  return `${date}-${city.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')}`;
}

function titleFor(city, date) {
  const month = new Date(`${date}T12:00:00Z`).toLocaleDateString('en-US', { month: 'long', year: 'numeric', timeZone: 'UTC' });
  return `${city} — ${month}`;
}

export function register(tool, wp) {
  tool(
    'list_events',
    {
      title: 'List events',
      description:
        'List So Many Angels events with their details and, for published events, whether the site has set up booking ' +
        '(booking link, reminders, CRM tag). Never includes families.',
    },
    async () => {
      const events = await wp('GET', '/wp/v2/sma-events?per_page=100&status=publish,draft&context=edit');
      return events.map(summarise).sort((a, b) => String(a.event_date).localeCompare(String(b.event_date)));
    }
  );

  tool(
    'get_event',
    {
      title: 'Get one event',
      description: 'One event with its details and set-up status. Use after publishing to confirm booking is ready.',
      inputSchema: { id: z.number().int().describe('Event id from list_events') },
    },
    async ({ id }) => summarise(await wp('GET', `/wp/v2/sma-events/${id}?context=edit`))
  );

  tool(
    'save_event',
    {
      title: 'Create or update an event',
      description:
        'Create a new event, or update one by id. A new event is a draft unless publish=true. An update keeps the ' +
        "event's current status unless publish is given (true publishes, false takes it back to draft and closes " +
        'booking). Publishing tells the website to open booking for families: it creates the booking sessions, the ' +
        'reminder emails (14, 7, 3 and 1 days before each booked session), the CRM tag and the event page. Always ' +
        'preview first (apply=false), show Matt, and call again with apply=true only after he says yes.',
      inputSchema: {
        id: z.number().int().optional().describe('Existing event id to update; omit to create'),
        city: z.string(),
        venue: z.string(),
        address: z.string(),
        event_date: z.string().regex(DATE).describe('YYYY-MM-DD'),
        start_time: z.string().regex(TIME).describe('HH:MM, 24-hour, venue local time'),
        end_time: z.string().regex(TIME),
        timezone: z.string().default('America/New_York'),
        capacity: z.number().int().min(1).describe('Number of photo sessions'),
        canvas_handover: z.string().describe('How families receive the canvas'),
        publish: z.boolean().optional().describe('true: publish (opens booking). false: back to draft. Omit on an update to keep the current status.'),
        apply: z.boolean().default(false),
      },
    },
    async ({ id, publish, apply, ...fields }) => {
      if (fields.end_time <= fields.start_time) throw new Error('The end time must be after the start time.');

      // An update without "publish" keeps the event as it is: a live event stays live.
      const current = id ? await wp('GET', `/wp/v2/sma-events/${id}?context=edit&_fields=status,meta`) : null;
      const status = publish === undefined ? (current ? current.status : 'draft') : publish ? 'publish' : 'draft';
      const wasLive = current?.status === 'publish';
      const moved =
        wasLive &&
        ['event_date', 'start_time', 'end_time', 'timezone'].some((k) => String(current.meta?.[k] ?? '') !== String(fields[k]));

      const body = {
        title: titleFor(fields.city, fields.event_date),
        status,
        meta: fields,
        ...(id ? {} : { slug: slugFor(fields.city, fields.event_date) }),
      };

      let whatHappens;
      if (status === 'publish' && !wasLive) {
        whatHappens =
          'Publishing opens booking: the site creates the booking sessions, reminder emails, CRM tag and event page, and the event appears in the sign-up and volunteer forms.';
      } else if (status === 'publish') {
        whatHappens = moved
          ? 'The event stays live with a new date or time. Families who already booked keep their old session: the site flags them for follow-up on the dashboard.'
          : 'The event stays live; its details are updated.';
      } else if (wasLive) {
        whatHappens =
          'Taking the event back to draft closes booking and hides it from families. Families who already booked keep their booking; the site pauses their reminders and shows a warning on the dashboard.';
      } else {
        whatHappens = 'Saved as a draft: nothing is visible to families yet.';
      }

      if (!apply) {
        return {
          ...(id ? { updating: id } : { creating: body.slug }),
          title: body.title,
          status: body.status,
          details: fields,
          what_happens: whatHappens,
          saved: false,
          next: 'Show this to Matt. Call again with apply=true if he approves.',
        };
      }
      const saved = await wp('POST', id ? `/wp/v2/sma-events/${id}` : '/wp/v2/sma-events', body);
      return {
        ...summarise(saved),
        saved: true,
        ...(status === 'publish' && !wasLive ? { next: 'Check set-up with get_event in a minute.' } : {}),
      };
    }
  );
}
