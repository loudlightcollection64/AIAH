# Plan: So Many Angels event system

Pitch: Saturday 17 October 2026, 9:00am–1:00pm, Ascender, Pittsburgh. Five-minute presentation,
five-minute Q&A.

## The idea

Matt's real problem is that his tools aren't connected, so he is the integration layer for every
event. We hand him a coding-agent setup (Claude with the workspace instructions, the knowledge vault
and fenced connectors) and prove what it can do by **building his whole wish list with it, in a
day**. The build itself is the demonstration.

Pitch line: "This is what your setup produced in a day. Here's how you'd add the next feature
yourself."

## Decisions

| Date | Decision | Why |
|---|---|---|
| 13 Oct | Keep WordPress; no rebuild on a new stack | His brief requires it; he edits the site himself in Elementor; his data and releases live there |
| 14 Oct | Deliver custom connectors regardless of his coding comfort | The connectors are what let him maintain custom work |
| 14 Oct | Build the full feature set as one WordPress plugin with HTML/CSS page templates | Installs by uploading one plugin; no Elementor Pro dependency; clean target for Claude Design |
| 14 Oct | Use his Fluent tools as the engines (Forms, CRM, Booking) | He already pays for them; they handle form handling, mail and slots |
| 14 Oct | Build and demo on the local copy with fictional families | Matt isn't sure his IONOS plan offers staging (unconfirmed); nothing touches the live site without his say-so |
| 14 Oct (round 2) | Event set-up moves server-side: publishing an event makes the plugin create booking sessions with FluentBooking's per-booking reminders, the CRM tag and the booking link. Claude's account becomes SMA Agent only; the connector shrinks to pages and event records | Four adversarial reviews (`docs/reviews/`): campaign reminders skip transactional contacts on FluentCRM 3.1.8; Claude-made events had no booking link; Fluent permissions let a leaked password reach children's data |
| 14 Oct | Own connector instead of the plugins' built-in MCP | FluentCRM's built-in tools include list, read, delete and email any contact |

## Architecture

```
Family's browser ──> Event pages, events list (plugin templates, designed in Claude Design)
                 └─> Sign-up form (Fluent Forms) ──> CRM contact, tagged event + awaiting-approval
                                                        │
Matt's WordPress admin ──> SMA Dashboard (plugin) ──────┤ approve: swap tag, email booking link
                                                        │
                           FluentBooking ───────────────┘ family picks a time; booking confirmation
                           FluentCRM ──> confirmation and 14/7/3/1-day reminders

Matt's Claude ──> SMA connector (fenced MCP) ──> events, forms, booking, tags, reminders
                                                  never entries, contacts or bookings
```

- **Plugin:** `plugin/sma-events`. Event record, dropdown filter, page templates, approval flow,
  dashboard, CSV export.
- **Connector:** `connector/wordpress`. Grows a tool or two for anything new the agent must set up.
- **Workspace:** `workspace/`. What Matt receives: `CLAUDE.md`, vault, runbooks.
- **Local site:** `site/`. Playground; `start.ps1` then `dev/seed.py`.

## Features

| # | Matt's feature | Build | Status |
|---|---|---|---|
| 1 | Family registration | Fluent sign-up form, live event dropdown; each submission becomes a CRM contact tagged with the event and `awaiting-approval` | Dropdown done |
| — | Approval (his priority list) | Dashboard approve button: swap tag to `approved`, email the FluentBooking link | To do |
| 2 | Event management | Event record generates a designed event page and an upcoming-events page | Record done; pages to do |
| 3 | Automated emails | Acknowledgement on sign-up, booking link on approval, FluentBooking confirmation, 14/7/3/1-day reminders | Reminders done (connector); rest to do |
| 4 | Volunteer applications | Volunteer form with portfolio upload and location; approve from dashboard | To do |
| 5 | Admin dashboard | WordPress admin page: events, families per stage, volunteers, follow-ups; CSV export for Google Sheets | To do |

## Rules

- **No real family data** in the repo, the local site, prompts or the demo. Fictional families only.
- **Family data never reaches the AI.** The dashboard reads entries in PHP behind Matt's login. The
  connector's fence stays closed to entries, contacts and bookings.
- **Nothing changes on the live site** without Matt's explicit yes.
- **Drafts first.** Anything the agent creates that families would see starts as a draft.
- **Never put a child's diagnosis in an email.**

## Build order (Wednesday 14 Oct)

1. **Design.** Pull brand colours and fonts from somanyangels.org; design the event page, events
   list and dashboard.
2. **Registration and approval.** Submission tags the contact, approve button, booking-link email.
   (Priority 1 in Matt's own list.)
3. **Event pages.** Generated from the event record.
4. **Emails** end to end. The local copy cannot send mail; a dev mail log captures every message.
5. **Volunteers.**
6. **Dashboard and CSV export.**
7. **Demo script and recording.**

Steps 3, 5 and 6 are independent once 1 and 2 are in, so they can run in parallel in separate
files.

## Demo script (draft)

1. Matt's Claude: "New event: Pittsburgh, November 14, 10 to 12, Cranberry studio, 20 sessions."
   The agent previews, Matt approves, and the event page, dropdowns, booking sessions, tag and
   reminder drafts all appear.
2. A fictional family signs up on the event page.
3. Matt approves them on the dashboard; the booking-link email appears in the mail log.
4. The family picks a time; the confirmation appears.
5. The dashboard shows them booked; reminders are scheduled.
6. A live change: Matt (or a team member in his place) asks for a small feature and it ships.

## What we measure

- Wall-clock build time and tokens for steps 1–6
- Commits (git log is the audit trail)
- Steps a new event takes before and after (today: hand-built page, hand-typed dropdowns, hand-sent
  emails)

## Thursday 15 Oct: install with Matt

- Email 2: install Claude extension (`sma-wordpress.mcpb`), workspace folder, restricted WordPress
  user plus application password.
- 20–30 minute call. First live use is read-only: `check_connection` and the list tools.

## Open questions

- Minimum Fluent permissions for the restricted WordPress user
- Whether Claude's built-in Google connectors work with his Google for Nonprofits account (calendar)
- How families find out their time today (Matt pointed us to a mock sign-up on the live site)
- Matt's real confirmation and reminder wording (asked for, not received)
- His form and Elementor exports (asked for, not received)
- Judging rubric (linked in the 12 Oct hackathon email; not yet read)

## Risks

- **Version gap.** Matt runs FluentBooking 2.2.0 and FluentCRM 3.1.8; we test on 2.5.0 and 3.2.6.
  Some request formats may differ, and some plugin safety checks don't exist in his versions (the
  connector enforces them itself).
- **Late registrants miss scheduled reminders.** Campaigns snapshot recipients. FluentCRM Pro's
  "tag applied" automation fixes this on his site; not available locally.
- **Live demo failure.** Keep a recorded run as backup.
- **Site hygiene.** Debug output shown to visitors, PHP 7.4, outdated plugins, fixed-offset
  timezone. See `workspace/vault/tools/site-health.md`. Raise with Matt; don't fix unasked.
