# Local site

A local WordPress copy built with WordPress Playground. It runs on Node; no Docker or PHP install
needed. It mirrors Matt's setup as far as free software allows: PHP 7.4, latest WordPress, the
Solace theme, and the free versions of his plugins. After the seed it also looks like
somanyangels.org: the live header, footer, colours, fonts and the main public pages, with the
event flow inside them (`replica/`, below).

## Start it

1. Fill `plugins-cache/` once with `../setup.sh` (the Fluent plugins, the Solace theme and
   Elementor, pinned versions with checksums).
2. From PowerShell: `./start.ps1`. Wait for "Ready!".
3. Load demo data: `python dev/seed.py`.

Log in at http://127.0.0.1:9400/wp-admin as `admin` / `password`.

Started on its own like this, **every start is a fresh, empty site**: Playground keeps WordPress
and its SQLite database in a temporary folder that it deletes when it stops. Re-run the seed after
each start.

`../demo.sh` keeps the site instead. Playground runs WordPress from a folder on disk either way,
so `start.sh` / `start.ps1` can mount a folder of ours as `/wordpress` (`SMA_SITE_DIR=<folder>` /
`-SiteDir <folder>`): an empty folder gets a new site; a folder that holds one starts it as it
was, with `blueprint-existing.json` (no steps) and no WordPress download or installer. `demo.sh`
seeds once into `.snapshot/<port>/work`, stops the site, saves a clean copy
(`.snapshot/<port>/pristine` plus the application passwords) and restarts; later starts reuse
`work` (`--reset` copies `pristine` over it). `dev/snapshot.py` does the copying and decides
when the copy is out of date: more than 3 days old (the demo dates count from the seed day,
recorded as `demo_date` in `dev/.local-credentials-<port>.json`), or a change to the plugin,
the seed scripts and their data, `replica/`, the blueprint, the plugins-cache versions or the
pinned Playground version. Copies are per port (pages and passwords hold the URL).

Seeding takes 15–20 minutes because every PHP request costs seconds in Playground on PHP 7.4,
whatever the code does; a saved copy starts in a minute or two.

Notes for Windows:
- Run from PowerShell, not Git Bash, which rewrites `/wordpress` mount paths.
- Plugins are mounted, not installed: Playground's own unpack step fails intermittently with
  "Operation not permitted".
- Auto-login is off. It redirects every request that lacks its cookie, which loops API clients
  such as the connector.
- Requests take several seconds each locally. The real site is much faster.

## Dev files

| File | Purpose |
|---|---|
| `dev/wp.py` | Minimal REST client for the local site (cookie login) |
| `dev/snapshot.py` | Saves, restores and checks the seeded copy `../demo.sh` reuses (`.snapshot/`) |
| `blueprint-existing.json` | Blueprint for starting a kept site: no steps |
| `dev/seed.py` | Demo events, application password, local copies of the two forms, then the copied pages |
| `dev/seed_site.py` | The copied public pages (home, about, contact, mission, donate, events, sign-up, volunteer, select a time), site title and front page |
| `dev/demo-events.json` | Fictional events, one already past |
| `dev/mu/sma-local-dev.php` | Local-only tweaks: app passwords over http, Fluent Forms field defaults |
| `dev/mu/sma-replica.php` | Local-only: prints the live header and footer in the Solace theme, loads the live styles, title-less page templates |

## The site replica (`replica/`)

`replica/capture.py` reads the public pages of somanyangels.org with GET requests only (one at
a time, with a pause) and writes `header.html`, `footer.html`, `pages/<slug>.html`, the live
Elementor and theme CSS into `assets/`, the logo, and `MANIFEST.md` with every source URL. The
one photo on those pages (children) becomes a drawn placeholder and the videos a labelled box;
the live forms, booking widget and hand-made events list become the local site's shortcodes
(`[fluentform]`, `[fluent_booking_calendar]`, `[sma_events]`). Family stories, testimonials and
event pages about specific children are never read.

At run time the mu-plugin hooks the theme's `solace_do_header` / `solace_do_footer` (the same
hooks the live site's header-footer plugin uses) and enqueues: Elementor's stylesheets from the
mounted plugin folder (Elementor is mounted but not activated: the pages are static markup and
running it would slow every request), the captured kit/header/footer/page CSS, the captured
theme customizer CSS, and `assets/replica.css` + `replica.js`, which approximate the two
Elementor Pro widgets the live header and contact page use (nav menu with its phone toggle,
form). The seed creates the pages with `sma_replica_template` = `full` (Elementor's full-width
template on the live site) or `default` (the theme's page wrapper, title hidden).

Re-capture after Matt changes the live site: `python -I site/replica/capture.py`, check the
diff, re-seed.

## What is not here

- Paid add-ons: Fluent Forms Pro, FluentCRM Pro, FluentBooking Pro, Elementor Pro. Anything that
  depends on them has to be built and tested on a staging copy of Matt's real site. The live
  header's nav menu and the contact form are Pro widgets; `replica/assets/replica.css` stands in.
- Ultimate Addons for Elementor, Air WP Sync, Site Kit and Matt's own logo-fix plugin.
- Any of Matt's data. The site starts empty; the seed adds fictional data and the copied public
  pages (no family stories, no photos of children, no testimonials).

## Notes

- The REST API index shows the same `mcp` and `wp-abilities` endpoints as the live site, so those
  ship with stock WordPress and the free plugins. Matt most likely never set them up.
- Timezone is set to America/New_York here. The live site uses a fixed `-04:00` offset; see
  `workspace/vault/tools/site-health.md`.
