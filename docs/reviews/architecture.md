# Architecture review: the whole approach

Skeptical principal-architect pass, 14 Oct 2026. Docs, Matt's sources and code spot-reads; nothing run.

## Verdict

The diagnosis is right: Matt *is* the integration layer, and he already pays for engines that can
do most of the job. The prescription overshoots. The team answered "connect the tools you own" by
building a 5,400-line custom plugin that re-implements what his stack already has (a custom post
type, page templates, a stage machine, CSV export, a volunteer pipeline). They then put an AI
connector in front of a job that should be one admin form. The result is impressive, careful and
well tested. It also leaves a one-person charity, running PHP 7.4 with no staging, as the sole
maintainer of a bespoke PHP codebase that is pinned to plugin versions he should upgrade next week.
**Ship less code and more configuration.** Configure Fluent natively, keep a thin plugin of maybe
300 lines for the true gaps, make routine operations deterministic, and use AI for authoring and
analysis. Pitch the judgment, not the agent count.

## The five strongest critiques

### 1. Reminders, Matt's priority 3, probably don't reach new families, and the test suite can't see it
Fix R1 (correctly) creates new families with FluentCRM status `transactional`. The connector schedules
reminders as **campaigns** with `sending_filter: list_tag` (`connector/wordpress/server/reminders.js:138-142`).
On Matt's FluentCRM 3.1.8, list/tag recipients are selected with status `subscribed` only
(`Campaign.php:311`, `getSubscriberIdsCountBySegmentSettings(..., 'subscribed')`). `draftRecipients`
then errors with "no subscribers found" when the count is zero (`CampaignController.php:485-489`). Expected effect: scheduling
fails, or it reaches only guardians who were already newsletter subscribers. `demo_e2e.py` never checks
that a reminder is delivered, and the connector is blind to contacts by design, so it can't
notice. On top of that, campaigns snapshot recipients (late sign-ups miss reminders), carry marketing
footers, and skip anyone who ever unsubscribed from the newsletter.
**Recommendation:** verify with one run. Then drop campaign reminders entirely and use per-booking
reminders: FluentBooking's own booking notifications (confirm multi-reminder support on Pro 2.2.0),
or a FluentCRM Pro automation started by the `booked` tag with wait steps. Reminders belong to the
*booking*, not to a mailing list. That also removes four of the connector's 13 tools.

### 2. The custom plugin duplicates his stack, and he can no longer edit his own event pages
Matt already has CPT UI, ACF, Elementor Pro (theme builder, dynamic tags), Fluent Forms Pro (native
FluentCRM feed), FluentCRM Pro (tag-applied automations, segments, CSV export) and FluentBooking Pro.
The plugin re-implements the event type, templates (which bypass Elementor, the tool he edits the
site in), the dashboard, export and volunteers. Plan decision 13 Oct reads "keep WordPress, his brief
requires it". But the brief asks for something that *works with* WordPress, and says "investigate
Softr, Replit, Bubble, Lovable before writing anything from scratch; compare Softr vs custom on day
one". No comparison is recorded anywhere.
**Recommendation:** add a one-page options table (native Fluent config / Softr+Airtable / custom
plugin) with total cost of ownership (TCO) to the report. Rebuild the core as configuration: an
event CPT in CPT UI + ACF with an Elementor single template; Forms → CRM feed applies tags; approval
is a tag change in FluentCRM (single or bulk), which starts an automation that emails the booking
link; per-booking reminders. Keep custom code only for the gaps: the dynamic event dropdown, creating
the booking session and tag on publish, and the diagnosis guard.

### 3. "AI operates the tools" is the wrong abstraction for a routine operation
The plugin already creates the tag and fills the dropdowns on publish. What is left for the agent is
a six-step preview/apply ritual (`runbooks/new-event.md`) for a job Matt specified as "create a new event
in two minutes", from "my Mac and ideally my phone". The `.mcpb` connector runs in Claude Desktop
only, so not on a phone. Each event costs AI usage, against his brief: "avoid per-message AI
charges for tasks ordinary automation can handle". It is also non-deterministic. The fence is good
engineering guarding a door this task doesn't need.
**Recommendation:** one "New event" admin screen that does everything in one save, works on a
phone and behaves the same every time. Keep AI where it adds value: drafting event-page copy and
emails in Matt's voice, sponsor and partner outreach, answering "how many Pittsburgh families still
need a time?" from aggregates, and building the next feature.

### 4. "Matt maintains it with Claude Code" has no safety net at the points that matter
The dev loop is Node + Python + WordPress Playground: a 15-20 minute seed, a 17-minute e2e, and a
WebAssembly PHP runtime that crashed twice during the build (build-log 03:55, 10:10). It has never
run on a Mac (per the install notes). The tests run on SQLite and free plugins, while production is
MariaDB and Pro. There is no PHP lint, no CI, no staging, no release tags, and
`/ship-plugin` means uploading a zip to production. Rollback is "deactivate", or an IONOS restore that
drops registrations made since the backup. The plugin runs raw SQL against Fluent's internal tables
(`fc_subscriber_pivot`, `fc_subscriber_meta`, `fluentform_submissions` in `dashboard.php`, `rest.php`,
`registration.php`), and R1 already showed behaviour changing between 3.1.8 and 3.2.6. The
site-health note rightly says "update Fluent, move off PHP 7.4". Every such update is an untested
compatibility event for this plugin. Finding 1 blocker and 8 majors took a fresh expert
review agent; Matt won't run that loop.
**Recommendation:** fix the site first (PHP 8.2+, Fluent updates, timezone, debug display off), then
test against *those* versions. Add `php -l` plus the connector tests in a GitHub Action, semver tags
and a changelog, a hosted staging copy (or a host that has one), and a "before you update Fluent"
checklist. Name a human steward: a local volunteer developer, one hour a month. Shrinking the code
(critique 2) is the biggest guardrail of all.

### 5. The data model is contact-centric, but the business object is a registration (child × event)
State lives in four places: Fluent Forms entries (child data, diagnosis), FluentCRM contacts (guardian),
a JSON map in contact meta (`sma_stages`, `sma_bookings`, `sma_signups`) plus a "latest stage" tag,
and event post meta. Airtable and Sheets still hold history, and the brief's open question "which
system is the master record?" is unanswered (`vault/tools/stack.md`). The per-event map is invisible
to FluentCRM's own UI and automations, so the tag Matt sees can contradict the dashboard. Siblings
collapse: `sma_signups[slug]` keeps one entry id per guardian per event (`registration.php:290-294`), so
a second child for the same event overwrites the first. No retention or erasure exists either (no
`wp_privacy_*` exporter/eraser), and diagnoses stay in form entries indefinitely.
**Recommendation:** make "registration" a first-class record, either one table or one SaaS base,
and declare the system of record in writing. Minimise first: replace the free-text diagnosis with
"eligibility confirmed" plus "accommodations needed". Most of the fence exists to protect a field
that may not need to be collected. Add a retention rule (auto-delete entries N months after the
event) and an erasure path.

## Risks the team isn't seeing

- **Pinning to versions that should die.** The compatibility rules lock the codebase to PHP 7.4 and
  FluentCRM 3.1.8, the exact items the team's own health check flags.
- **Admin is all-or-nothing.** `sma_manage_families` sees every family in every city. Affiliates
  in other cities (his stated growth path) need scoped access that this model can't express.
- **A second human who never existed.** A single team member is the bus factor. The kit assumes Claude Code
  availability on Matt's Team seat (flagged "untested"), plus Node, Python and Xcode tools.
- **Timezone.** The site runs at fixed `-04:00` until someone fixes it. Reminder and booking times
  shift on 1 Nov, before the first likely live event.
- **Inconsistent claims.** REPORT says 55 connector tests, build-log 54. Judges notice.
- **The diagnosis can leak through settings the plugin doesn't own** (R25). Checklist, not enforced.

## New directions

| Direction | Effort | Impact |
|---|---|---|
| **Configuration-first rebuild**: native Fluent feeds, automations and booking reminders; CPT UI/ACF/Elementor event template; plugin cut to the gaps | 1-2 days | Very high: TCO, upgrade safety, Matt edits his own pages |
| **Deterministic "New event" screen**, mobile-friendly, one save creates page, dropdown, booking session, tag and reminder schedule | 1 day | High: meets "two minutes" literally, no AI cost |
| **AI as author and analyst**, not operator: voice-matched drafting, aggregate-only Q&A over counts, a weekly digest ("3 families awaiting approval, 2 unbooked, event in 9 days") | 1 day | Medium-high, low risk |
| **Airtable as the ops/production base** (he has it, plus Zapier and Air WP Sync): registration × event records, an editing → print → reveal kanban, per-affiliate interfaces. Children's data needs a permissions review first | 2-3 days | High for phases 6-8 and affiliates |
| **Data minimisation + retention policy**: drop the diagnosis, add a retention rule, consent stored as a dated record | Half a day | High; cheapest safety win available |

Donors stay in Givebutter and newsletters in Mailchimp. Integrate exports; don't rebuild a donor CRM.

## Cut or simplify

- Public page templates and the hand-written CSS design system → Elementor theme template.
- CSV export and the custom volunteer pipeline → FluentCRM segments/export and a tag-driven form flow.
- Campaign-reminder tools in the connector (critique 1), and runbook step 2 (the plugin already
  fills dropdowns).
- The Playground kit as Matt's daily tool. Keep it for builders. Give Matt a recorded demo and a
  staging site.
- `/ship-plugin` run by Matt alone, until a staging site and a steward exist.

## Pitch changes

- **Lead with Matt's own acceptance test** from his brief (Pittsburgh event → fictional child →
  appointment → confirmation → dashboard), and show it passing.
- **Reframe "10 agents in 2 hours."** For a cautious nonprofit it reads as "5,000 lines nobody wrote".
  It also hides an engineer-written 264-line spec, purpose-built local tooling, ~2.1M tokens and a review that found
  1 blocker + 8 majors, none of which Matt's own setup reproduces. Tell the honest version: "AI wrote
  it fast; an independent AI review caught a consent bug that only appears on Matt's plugin versions;
  here is the process that made it safe." The process is the asset.
- **Say what you chose not to build,** and show the options comparison Matt asked for.
- **Prepare for:** "Did a reminder reach a family in your test?" · "Who fixes it at 11pm before an
  event?" · "What happens when FluentCRM updates?" · "Why does AI need to create an event; why not
  a form?" · "Is anything live?" · "Where is the diagnosis stored, and for how long?" · "Did you
  evaluate Softr as Matt asked?"
