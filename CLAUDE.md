# So Many Angels kit

You are working with Matt Meiers, founder of So Many Angels, a nonprofit that gives children with
cancer a costumed photo session and a printed canvas, free. Matt is comfortable with AI but is not
a programmer: explain what you will do in plain words before doing it, show him previews, and keep
answers short. Read `REPORT.md` once for the big picture.

## What's here

| Path | What it is |
|---|---|
| `plugin/sma-events/` | The WordPress plugin: event records (publishing one sets up booking sessions, reminder emails and a CRM tag on the site), sign-up → approval → booking, volunteers, the dashboard, CSV export, public event pages, emails, settings |
| `connector/wordpress/` | The fenced connector: Claude can read pages and read, save and publish event records, nothing else. It never sees family records. |
| `workspace/` | Knowledge about So Many Angels (`vault/`), operating rules (`CLAUDE.md`) and step-by-step runbooks |
| `site/` | A local copy of the website for trying things safely; `site/dev/` holds demo data and tests |
| `docs/` | How it was built: plan, build log, code review findings, demo script, design notes |

## Ground rules

1. **No real family data, ever,** in this folder, in the local site, in tests or in what you read.
   Use obviously fictional people with `@example.com` addresses.
2. **Never put a child's diagnosis** in an email, the CRM, a page, a log or a CSV. It stays in the
   form entry only.
3. **The live site (somanyangels.org) changes only when Matt says so,** step by step, after a
   backup. Everything else happens on the local copy first.
4. **Don't widen what Claude's account or the connector can reach.** Claude's WordPress account
   (`claude-agent`, role SMA Agent) manages event records only, and the connector's fence
   (`connector/wordpress/server/wp.js`) allows only pages and event records. Work that needs
   more privilege belongs in the plugin, server-side, triggered by something Matt does (as
   event set-up is triggered by publishing). If a task seems to need family data through the
   connector, stop and explain why it can't, then offer another way.
5. **Plugin code must run on the live site's versions:** PHP 7.4 (no `match`, named arguments,
   `?->`, union types, enums, `readonly`), WordPress 7.1, Fluent Forms 6.2.5, FluentCRM 3.1.8 and
   FluentBooking 2.2.0, all with Pro on the live site. The local copy runs those same free
   versions. Check any Fluent hook or function in `site/plugins-cache/<plugin>/` before you use
   it.
6. **Emails sound like Matt:** `workspace/vault/org/voice.md`.
7. **The forms are Matt's real forms.** The local sign-up and volunteer forms copy the live ones
   field for field. Fluent Forms gave the live fields generic names (`input_text`,
   `input_text_3`, `dropdown`…); `site/dev/live_form.py` lists what each one is. The plugin
   reads them through the name lists in `sma_signup_fields()` (`registration.php`),
   `sma_volunteer_fields()` (`volunteers.php`) and `sma_dash_child_field_names()`
   (`dashboard.php`). `input_text_1` on the sign-up form is the diagnosis: never read it. If Matt
   deletes and re-adds a field on the live form, its name changes: re-read the public form page
   and update the lists.
8. **Commit after each working change** with a clear message, so anything can be undone.

## Everyday commands

| Command | What it does |
|---|---|
| `install.sh` | Mac installer: puts the kit in a chosen folder, installs what's missing (developer tools, Node.js, Claude Code), runs setup, starts `/demo` |
| `./setup.sh` | One-time setup (Node.js, Python, plugins, connector packages) |
| `./demo.sh` | Start the local site with fictional data (seeds once, then reuses the saved copy) |
| `./demo.sh --reset` | Start again from the clean demo data (drops changes made during a demo) |
| `./demo.sh --reseed` | Load the demo data from scratch and save it (15–20 minutes) |
| `./demo.sh --stop` | Stop the local site |
| `/demo`, `/test`, `/new-event`, `/ship-plugin` | Slash commands for the same jobs (see `.claude/commands/`) |
| `/update-plugins` | Pin the local copy to the plugin versions on the live site, then test |

`.claude/settings.json` pre-approves the kit's own scripts and tests, so Matt isn't asked before
each one; anything else still asks. Monthly upkeep: `workspace/runbooks/monthly-maintenance.md`.

The local site is at http://127.0.0.1:9400 (log in as `admin` / `password`). It is kept on disk
in `site/.snapshot/9400/`: the first `./demo.sh` seeds it (15–20 minutes) and saves a clean copy;
later starts reuse it in a minute or two, as it was left (`--reset` for the clean copy). The demo
dates count from the seed day; `demo.sh` seeds again by itself when the copy is over 3 days old
or the plugin, seed data or copied pages changed (`site/dev/snapshot.py`). Plugin edits still show
on the next page load. Emails are never really sent: they appear in
Tools → Mail log (dev). PHP errors are at `GET /sma-dev/v1/debug-log` (admin), and
`site/dev/wp.py` is a small client for scripts.

## Running events (operating, not building)

When Matt asks to set up an event, follow `workspace/CLAUDE.md` and
`workspace/runbooks/new-event.md`, using the `sma-wordpress-local` connector tools. In this folder
that connector runs as `claude-agent` with the same restricted role as on the real site. Every
change is previewed first and saved only after Matt says yes. Publishing an event opens booking:
the site creates the sessions, reminders and tag itself.

## Building a change

1. **Agree what "done" looks like,** in Matt's words, before writing code.
2. **Change the plugin** in `plugin/sma-events/`. It is live-mounted, so the local site picks up
   edits on the next page load without a restart.
3. **Add a check** for the new behaviour to `site/dev/demo_e2e.py` (or a focused `demo_*.py`).
4. **Run `/test`** (the quick core check, about 10 minutes) while you work, and **`/test full`**
   (every check, about 25 minutes) before showing Matt. Everything must pass, and the debug log
   must show no new `sma` errors.
5. **Show Matt the result** on the local site, then commit.

Larger features: write a short plan in `docs/` first and agree it with Matt.

## Shipping to the live site

Only on Matt's instruction; follow `workspace/runbooks/going-live.md` (it uses `/ship-plugin`). In
short:
1. Back up the site in IONOS.
2. Build the plugin zip and upload it in WordPress.
3. Fill in So Many Angels → Settings.
4. Check the sign-up form's own settings: no diagnosis in notifications or CRM feeds.
5. Create `claude-agent` with the SMA Agent role and nothing else.
6. Test one fictional event and sign-up, then delete them.
