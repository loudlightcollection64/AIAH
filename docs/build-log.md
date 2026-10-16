# Build log

How the So Many Angels system was built, reviewed and tested, 13–16 October 2026. Agents ran on
Anthropic's models: a Fable orchestrator directing Opus coding agents, each working in its own
git worktree; a lead agent planned the project and maintained the connector, workspace and local
site. All testing used fictional families on a local copy of the site; nothing was changed on
somanyangels.org.

## Round 1: first working version (14 October, 02:21–04:18)

Spec: `docs/build-spec.md`. Ten Opus agents, never more than three at once, about 1.8 million
tokens, plus about 290,000 for the orchestrator.

| Package | Result |
|---|---|
| WP0 brand and design system | Design tokens and components in `assets/sma.css` |
| WP1 registration and approval | Sign-up → contact, stage and acknowledgement; approval emails the booking link |
| WP2 public event pages | `/event/<slug>/` pages with the sign-up form preselected |
| WP4 volunteers | Application, approval with details email, drafted declines |
| WP5 dashboard and export | Events overview, families per event, CSV for Google Sheets |
| WP6 demo data and acceptance test | 17 fictional families, 3 volunteers; `demo_e2e.py` makes a real FluentBooking booking |
| Review | 1 blocker, 8 major and 16 minor findings (`docs/review-findings.md`) |
| Fixes A, B, C | All findings fixed or documented; per-event family stages |

## Round 2: adversarial review and hardening (14–15 October)

Spec: `docs/build-spec-2.md`. Four independent reviewers attacked round 1, each with one lens:
the client (`docs/reviews/requirements.md`), a security tester (`security.md`), a code reviewer
(`code-quality.md`) and an architect (`architecture.md`). Nine work packages followed:

- **Server-side provisioning:** publishing an event creates its FluentBooking session, per-booking
  reminders (14, 7, 3, 1 days), confirmation, CRM tag and booking page, so Claude's account needs
  no Fluent permissions at all.
- **Family data correctness:** siblings, duplicates, no overwriting existing contacts, the
  no-event group, medical-looking fields hidden by label, consents.
- **Settings screen**, with family data visible to administrators only by default.
- **Public URLs** and the `[sma_events]` shortcode for the site's own events page.
- **Email previews:** nothing reaches a family without the organiser seeing it first; thank-yous.
- **Code quality:** shared rules, dashboard split, dead code removed, uninstall.
- **Least-privilege tests** for Claude's restricted account.

A fresh reviewer then checked the fixes (`docs/reviews/round2.md`) and found more: site-owned
event meta was writable over REST, cancelled bookings kept their reminders, and the connector
could unpublish a live event on update. All were fixed and covered by tests.

## Round 3: site copy, speed and dashboard features (15–16 October)

- **Site copy:** the local demo site reproduces somanyangels.org's theme, header, menu and nine
  pages from public pages only, with every child's photo and story left out
  (`site/replica/MANIFEST.md`).
- **Saved demo site:** the demo data loads once and is kept; later starts take about two minutes,
  and the copy reloads itself when it is over three days old or the plugin changes.
- **Faster tests:** the acceptance test runs independent sections side by side, and a quick core
  check covers the client's acceptance path.
- **Dashboard:** reminder dates on each booked family, approval nudges, bulk "mark attended",
  canvas tracking (edited, printed, delivered), clearer stage menu and table wrapping.
- **Daily digest email** and a **site checks** panel on Settings.
- **Kit:** one-line Mac installer, pre-approved commands for Claude Code, `/update-plugins`, and
  going-live, monthly-maintenance and data-retention runbooks.

## Test results

| Run | Result |
|---|---|
| Round 2, final full run on the client's plugin versions | 228 of 229 passed; the one failure was in the test helper, then fixed |
| Site copy, full run | 227 of 227 passed |
| Quick core check (client's acceptance path) | 94 of 94 passed in 7 min 54 s |
| Faster full run | 226 of 226 passed |
| Connector rule tests | 50 of 50 |
| Connector walkthrough as Claude's restricted account | 19 of 19, refused on every family-data route |
| Public page tests on the site copy | 29 of 29 |

The round 3 dashboard features, digest and site checks add new sections to `demo_e2e.py`; run
`/test full` for their result on the current code.
