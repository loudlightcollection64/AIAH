# Requirements review: what Matt asked for vs what he gets

Reviewer stance: Matt Meiers, client. Sources: his brief (PDF p1-3), the ChatGPT summary (§§3-12),
his answers in `Hackathon Questions.pdf` (not in `sources/`), Site Health, IONOS
screenshot. Code read at 328a3de; connector `npm test` run (55/55). Nothing else was executed.

## Verdict

The sign-up → approve → book path is real, careful work. It runs on his real Fluent forms and plugin
versions, keeps the diagnosis out of everything, and declines are drafted, never sent. But Matt said
"get features 1-3 working end to end" and "a functioning registration-to-reminder system is worth far
more than an impressive demo". The **reminder half is not delivered**: reminders are one-off CRM
campaigns that Claude schedules by hand for each event. On his FluentCRM 3.1.8 they very likely skip
every new family, and they would go to declined families and volunteers. Events created the way
REPORT.md tells him to (through Claude) also get no booking link. Approving a family for such an
event sends nothing. The demo hides both gaps. Fix those two before Thursday, or say so plainly.

## Requirements

| Requirement (source) | Status | Evidence | Gap |
|---|---|---|---|
| F1 Family registration (PDF p1) | **Met** | `registration.php:174-330`; dropdown self-fills `event-record.php:271` | Siblings for one event collapse into one row: `sma_signups[slug]` keeps only the last entry (`registration.php:310`) |
| Approval step (ChatGPT §2 Ph3, §10 P1) | **Met** | `approval.php:96-135`, dashboard buttons | Approve sends the email at once, with no preview and no confirm (`families-table.php:68-77`) |
| Eligibility review (§2 Ph2) | Partly | Only approve/decline, plus an internal reason | No eligibility criteria and no diagnosis view (by design). Not explained to Matt |
| Duplicate detection (§2 Ph2) | **Not met** | Contacts merge by email only | Same child twice or two siblings: no warning. Never mentioned |
| F2 Event in 2 min, registration opens automatically (PDF p1) | Partly | `save_event` and auto-dropdown work | Only through Claude: wp-admin has no event form, only raw custom fields. Booking link never set (Issue 2) |
| F3 Confirmations (PDF p1) | **Met** | `emails/family-received.txt`, `family-approved.txt`; FluentBooking's own confirmation | The booking confirmation is FluentBooking's default text, not "your voice" |
| F3 Reminders 14/7/3/1 days (PDF p1, §10 P2) | **Not met** | `connector/.../reminders.js:121-147` | Manual, per event, snapshot recipients, wrong recipients, likely zero new families (Issues 1, 3) |
| F3 Follow-ups (PDF p1, §8) | **Not met** | No template or automation | No post-event thank-you to families or volunteers |
| F4 Volunteers: apply, upload portfolio, choose location, approve (PDF p1) | Partly | `volunteers.php`; approve per event with a voice email | Portfolio and location fields exist only on the local form ("proposed additions", commit 328a3de). Upload is Pro-only and untested (the demo script) |
| F5 Dashboard: registered, attended, canceled, follow-up (PDF p1) | **Met** | `dashboard.php`, stages, per-event counts, CSV | Attended/canceled are set by hand. Consents (social/story) aren't shown |
| Works with WordPress (tech req) | **Met** | One plugin, PHP 7.4-safe, his Fluent versions | Event templates untested on his Solace + Elementor theme |
| Mobile-friendly (tech req) | Partly | `sma.css` media queries; `data-label` stacked tables | Never checked at phone width (no test or screenshot). Phone can't run Claude event setup |
| One database + Google Sheets export (tech req) | Partly | CSV export (`export.php`) | Manual download only. His Sheet (matt@somanyangels.org) isn't connected. Airtable (Air WP Sync is **active**) is never addressed |
| No programming (tech req, §3) | Partly | Dashboard is click-only | Setup needs Terminal, curl, xcode-select, Node. Kit never run on a Mac (the install notes). He never answered the comfort-level question |
| Secure family info (tech req, §9) | **Met, strongly** | Fence plus restricted role; diagnosis allow-lists; 55 fence tests | Access by volunteer role isn't supported: admins and editors only |
| Preview + confirm before family-affecting actions (§8) | Partly | Every connector write previews first | Dashboard Approve has no preview. Reminders preview the text but not who gets them |
| Avoid per-message AI costs; operate after students leave (§3) | Partly | Sending runs in WP/Fluent | Each event's reminders need Claude. Upkeep needs Claude Code and git |
| Investigate Softr/Replit/Bubble/Lovable, compare on day 1 (PDF p3) | **Not met** | No mention anywhere | Matt will ask why |
| Verify list (§11): forms, CRM, booking plugin, source of truth, staging | Partly | `vault/tools/stack.md` | SSA vs FluentBooking and Airtable vs WP are still "unknown". `plan.md:24` states "no staging" as fact; Matt said "not sure" |
| Success (§12): register → appears → select time → comms, no manual moving | Partly | `demo_e2e.py` 55 checks on a test-prepared event | Breaks for events set up through the documented path (Issue 2) |

## Top 10 issues (ranked by what Matt would care about)

1. **Reminders will probably reach no new family.** New contacts are created `transactional`
   (`stages.php:216`, the fix for review R1). FluentCRM 3.1.8 picks campaign recipients with
   `Subscriber::where('status','subscribed')` (`plugins-cache/fluent-crm/app/Models/Campaign.php:311,480`).
   No test checks the recipient count (`smoke.mjs:106-107` only schedules). *Fix:* send reminders
   per booking with FluentBooking's built-in reminder emails (`EmailNotificationService::reminderEmail`,
   FluentBooking 2.2.0). They go only to booked families, include the appointment time, and catch late
   bookings. Or use a FluentCRM Pro automation that sends to transactional contacts. Prove it with a
   recipient-count check.
2. **Events made through Claude have no booking link.** `booking_url`, `booking_session_id` and
   `booking_calendar_id` are "set by the site" (`events.js:9-10`), but nothing in the plugin or
   connector sets them. Only the test scripts do (`demo_families.py ensure_booking`,
   `demo_signup.py:81`). Approving then flags "needs follow-up" and emails nothing, and bookings
   can't be matched to the event (`approval.php:203`). *Fix:* a `link_booking_session` step in the
   connector and runbook that creates the booking page and writes all three fields. Then run the
   demo's Columbus event through approve and book.
3. **Reminders go to the wrong people.** The event tag stays on declined/canceled families and is
   added to volunteers (`volunteers.php:259`). Exclusions are refused (`wp.js:77`). This breaks his
   rule to "prevent accidental emails to the wrong family". *Fix:* target booked families only (fix 1
   does this).
4. **Reminders aren't automatic.** Claude schedules each one by hand, and only after families
   register (`new-event.md` §5.3). Anyone who signs up later misses them. The workspace's own rule 5
   says routine sends "run without an AI session". *Fix:* same as 1.
5. **Thursday setup is fragile for a non-programmer.** Terminal commands, a 20-minute first run, an
   unpinned `@wp-playground/cli@latest` (`site/start.sh:31`), WebAssembly crashes in `site/.site.log`,
   and never run on a Mac. *Fix:* a teammate runs `./setup.sh && ./demo.sh --quick` on a Mac today.
   Pin the Playground version. Have a recorded fallback ready.
6. **Live install may not find his form.** Detection goes by title prefix "SMA Session Signup"
   (`registration.php:20-55`). A public page doesn't show a form's title, so that was never
   confirmed, and there's no settings screen to set `sma_signup_form_id`. *Fix:* a small settings
   page, or ask Matt for the form titles.
7. **No follow-up or thank-you emails** (feature 3, §8). *Fix:* a post-event thank-you draft, or at
   least list it under Limits.
8. **Siblings and duplicates.** One guardian with two children at one event shows as one child, and
   the second entry overwrites the first. *Fix:* key sign-ups by entry, and flag repeat sign-ups on
   the dashboard.
9. **Approve sends without a preview.** *Fix:* a confirm step that shows the email before sending.
10. **The brief's platform comparison and his ChatGPT habit are ignored.** He uses ChatGPT first
    (`stack.md:51`), and his brief asked for a Softr-vs-custom comparison. *Fix:* one REPORT
    paragraph on why WordPress + Claude, and what ChatGPT can still do (drafting from `voice.md`).

## Overclaims in REPORT.md / README.md

- "Reminders 14, 7, 3 and 1 days before are drafted for your approval and then scheduled." This is
  per-event and manual, likely reaches no new family, and wrong recipients are possible (Issues 1-4).
- "Nothing reaches a family without your yes" and "Reminders can only go to one event's families."
  Approval emails go out without a preview, and the event tag includes volunteers and declined
  families.
- "Acknowledgement, booking link and booking confirmation, in your voice." The confirmation is
  FluentBooking's default text.
- "Volunteers apply with their role, portfolio link and preferred location." Not on his live form.
- "Enter an event once… its public page… sign-up option and CRM tag appear." True, but the booking
  link doesn't, so the chain breaks at approval.
- "The plugin also works with your existing… forms as they are." Depends on the unverified form title
  (Issue 6).
- "We tested… on your exact plugin versions." These were the free cores; his Pro add-ons, theme and
  Elementor were untested (the team's own notes in the install notes say so).
- "53 of 53 checks." The latest commit says 55; the number is stale.
- "Each is a conversation with Claude away." This undersells how much work photo tracking and donor
  management really are.
- "Built in about two hours." The six packages took about 2 hours, but work continued until 13:25.
  Fine for the pitch, misleading as an estimate for him.
- Matt's zip includes `docs/plan.md`, with its pitch line and "regardless of his coding comfort".
  Reword it or drop it from the kit.

## Credit where due

- **Privacy is excellent**, and it is his §9 almost line for line: diagnosis allow-lists everywhere,
  a fenced connector with 55 passing tests, a restricted WP role derived from the plugins' permission
  code, and fictional data only.
- It reuses tools he already pays for, with no new subscriptions, which respects "don't rebuild" and
  "avoid duplicates".
- It matches his **real live form field names**, keeps his "Individual Session" options and drops
  stale dated ones. That fixes the October 4 problem he would notice first.
- Declines are drafted and never sent, and a booking made before approval gets flagged. Both show
  real judgement about sensitive cases.
- The R1 catch (force-resubscribing unsubscribed guardians on 3.1.8) was version-specific diligence.
  Issue 1 is the side-effect it needs next.
- The emails are warm, short, first names, "Thank you!". That is his voice.
- Site-health findings (debug display on, PHP 7.4, the DST timezone trap) are useful whatever he
  decides.
