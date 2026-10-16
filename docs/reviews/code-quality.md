# Code quality review

Scope: `plugin/sma-events/`, `connector/wordpress/`, `site/dev/*.py`, shell scripts, `tools/`, `CLAUDE.md` and `.claude/commands/`. Static reading only; `npm test` (55/55 pass), `bash -n` and `py_compile` all clean. 14 Oct 2026.

## Verdict

**Grade: C+.** The safety-critical parts are good: the connector fence, the diagnosis allow-lists, the nonce and capability checks, and the logging discipline. Some comments explain the reasoning with file:line citations. The rest reads like five agents working in parallel, which it was. The same domain rules (stage sets, the "no event" key, contact upsert, draft notes) are written out in several places and have already drifted apart. The plugin defends itself against its own files being missing. The docs and test scripts describe forms and versions that changed on 14 Oct. Claude Code maintaining this will trust the comments and copy the nearest duplicate, so every duplicate and every stale comment will turn into a bug later.

## Top 15 issues (ranked by maintainability cost)

**1. The live form's "no event" choice doesn't fit the `always-*` model (probable bug, verify locally).** `registration.php:257,282`, `dashboard.php:281-295`, `stages.php:304-311`. All no-event logic keys on values starting with `always-`. The real sign-up form's option is "Individual Session — Contact Us" (`seed.py:212`). It gets the slug `individual-session-contact-us` and is treated as an event slug: the plugin logs "names an event with no event record", stores the stage under that key, and the `_none` dashboard row then shows "No stage" with no child fields. No test submits that value (`demo_e2e.py:184` only checks that the option survives). *Fix:* one `sma_is_no_event_choice($slug)` that means "not an existing sma_event", used by registration, volunteers and the dashboard. Add an e2e sign-up with the live value.

**2. Docs and commands tell the agent to do broken things.**
- `workspace/runbooks/new-event.md:34` says to run `set_dropdown_options` on the `event` field. The live field names are `dropdown` and `dropdown_1`, and the plugin rebuilds that list at render anyway (`event-record.php:271`).
- `smoke.mjs:69` looks for `f.name === 'event'`, so it crashes with a TypeError.
- `.claude/commands/test.md:14` runs smoke from the repo root, but smoke spawns `server/index.js` relative to the working directory.
- `connector/wordpress/README.md:86` and the smoke header name `.local-credentials.json`, but `local.mjs` uses `-9400.json`.
- `demo_e2e.py:8` points at `site/start.ps1`, which `pack_kit.py:27` leaves out of the kit.

*Fix:* drop the dropdown step from the runbook, make smoke use `live_form` names and resolve paths from `import.meta.url`, and fix the references.

**3. Hard-coded demo dates expire next month.** `demo-events.json`, 17 dates in `site/dev/*.py` and `smoke.mjs:213-261` treat `2026-11-14-pittsburgh` as "upcoming". After 14 Nov 2026, `/test` fails and the demo shows no events. *Fix:* in `seed.py`, generate dates as today plus an offset; scripts read slugs from the seed instead of literals.

**4. Connector timezones are wrong.** `dates.js:224-236` defaults to `America/New_York`. `reminders.js:248,307` calls `isFuture()` with no zone, but the tool description says "site timezone", so Kansas City and Indianapolis reminders are judged in Eastern time. `save_event` also defaults to NY (`events.js:51`). `nowTime` uses `hour12:false` instead of `hourCycle:'h23'`, which can print "24:05" depending on the ICU build. *Fix:* pass the event's `timezone` meta (schedule_reminder already fetches the event) and remove the defaults.

**5. Two copies of the contact-registration pipeline, already drifted.** `registration.php:217-318` and `volunteers.php:207-322`:
- The family path keeps the existing email casing (`:240`) and sends empty `last_name`/`phone`.
- The volunteer path filters out empty values (`:233-237`) and doesn't keep the casing.

So a family re-signing can blank data that a volunteer application deliberately protects. *Fix:* one `sma_upsert_contact(array $fields, array $data)` plus `sma_attach_event_from_form()`.

**6. R21 is still open, and the comment about it is false.** `rest.php:33` says `sma_contact_ids_with_tags` is "the one place the plugin reads the tag pivot". The same stage-tag join is copied at `dashboard.php:390-396` and `rest.php:257-263`. Tag helpers used by the dashboard and volunteers live in `rest.php`. *Fix:* move all pivot and tag SQL into `stages.php` behind `sma_stage_tags_for_contacts($ids)`.

**7. Magic strings for domain rules.**
- `'_none'` is two constants (`SMA_DASH_NO_EVENT`, `SMA_STAGES_NO_EVENT`) plus raw literals at `registration.php:295`, `stages.php:599` and `volunteers.php:270,518,613`.
- "Confirmed" is written as `['approved','booked']` four times, with an `'attended'` variant at `registration.php:273`.
- The approve and decline eligibility rules live only in the view (`families-table.php:98-99`), and `sma_decline_family` doesn't enforce them.
- The "DRAFT, NOT SENT" note block is copied three times (`approval.php:157`, `dashboard.php:892`, `volunteers.php:467`).

*Fix:* one constant, `sma_stage_is_confirmed()`, `sma_can_approve($stage)` checked in the service, and `sma_save_draft_note()`.

**8. The plugin guards against its own files.** The loader at `sma-events.php:52-70` skips missing includes ("Each later package adds its file name here"). There are also 20 `function_exists('sma_…')` checks on the plugin's own functions (`dashboard.php:64,305,532`, `event-record.php:245,391`, `export.php:173`, `sma-events.php:72`). A missing or renamed file silently turns into "Volunteers: not installed" instead of an error. *Fix:* `require_once` a fixed list and delete the self-guards. Keep guards only for the Fluent plugins.

**9. `dashboard.php` (955 lines) mixes four jobs.** It holds the query layer, routing, `echo` markup and POST handlers. `export.php` depends on `sma_dash_families()`, so the CSV hangs off a view file. `sma_dash_decline_no_event` (`:857-905`) is a third copy of the decline service. *Fix:* split into `includes/families.php` (queries, no `dash_` prefix), `admin/controller.php` (handlers), and the existing views.

**10. Comments cite the wrong versions, and the local site isn't pinned.**
- `pages.php:331` cites Fluent Forms 6.2.15.
- `booking.js:2`, `reminders.js:2` and `wp.js:29` cite FluentBooking 2.5.0 and FluentCRM 3.2.6. The live versions are 2.2.0 and 3.1.8.
- `blueprint.json` uses `"wp": "latest"`, and both start scripts run `@wp-playground/cli@latest`, yet `CLAUDE.md:25-27` says the local copy runs the live versions.
- The `start.ps1` header says to fill the cache from `latest-stable.zip`.

*Fix:* pin WordPress 7.1 and the CLI version, re-check the booking and campaign recipes against the 2.2.0/3.1.8 cache, and correct the comments.

**11. Connector tools are untested.** The tests cover the fence, the body rules, `toHtml`, `addDays` and the timeout. No tool handler has a test: `save_event` (slug rules; an update must resend every field and rewrites the title), `create_booking_session` (session maths, only reads `calendars.data` while `index.js` uses `listAt`), `create_reminder_drafts` (idempotency hangs on `per_page=200`), `set_dropdown_options`, `isFuture`/`today`, `walkFields`. *Fix:* `register(tool, wp)` already allows injecting a fake `wp`; add one test file per module.

**12. A future change could bypass the fence through the query string.** `wp.js:143` appends the raw query after `?rest_route=`, and in PHP the last `rest_route` wins. No current tool can do this, because queries are constants or built with `URLSearchParams`. The first tool that passes a raw query reopens it. *Fix:* reject any query that contains a `rest_route` key, and add a fence test for it.

**13. The Python demo scripts are copy-paste.**
- `check()` exists in 5 files, `must()` in 3, `first_list`/`wp_first_list` in 3, `event_by_slug` in 3.
- `demo_signup.py:41-82` and `demo_volunteer.py:43-82` differ by three lines.
- `VOLUNTEER_STAGES` has two values in `demo_dashboard.py:39` and three in `demo_families.py:55`.
- `demo_e2e.py` imports five other scripts as libraries.
- `demo_families.py:312` copies the connector's FluentBooking recipe in Python.

*Fix:* `site/dev/sma_testlib.py`, with the stage lists read from `/sma/v1` rather than copied.

**14. Plugin lifecycle and package hygiene.**
- There is no `uninstall.php` or deactivation hook: the `sma_agent` role, admin/editor caps, two options and the transients outlive the plugin.
- Activation is wired twice (`sma-events.php:72` plus `pages.php:56`).
- The timezone lookup is written three times (`event-record.php:166`, `pages.php:171`, `dashboard.php:236`).
- In `package.json`, `"main": "index.js"` is wrong (the file is `server/index.js`), `"private": "true"` is a string, and there is no `engines` field.
- The version is written in three places (`package.json`, `manifest.json`, `index.js:15`).

**15. Inconsistent conventions in views.** Views get data three ways: `extract()` (`dashboard.php:581`), a `$sma` array (`pages.php:308`), and template-level `$sma_*` globals. Five nearly identical hidden-field forms sit in `families-table.php` and `admin/volunteers.php`. There is no text domain, but three `esc_html__()` calls borrow core strings. *Fix:* pick one way to pass data and add `sma_dash_action_form($action, $cid, $fields)`.

## Quick wins (under 30 minutes each)

- Fix `package.json` (`main`, boolean `private`, `"engines": {"node": ">=18"}`).
- Point `test.md:14` and the README at `-9400.json` and run smoke from `connector/wordpress`.
- Replace the `'_none'` literals with `SMA_STAGES_NO_EVENT` and delete `SMA_DASH_NO_EVENT`.
- Correct the version citations (`pages.php:331`, `booking.js:2`, `reminders.js:2`, `wp.js:29`) and the false "one place" comment (`rest.php:33`).
- Remove the stale "NOT from Matt's live form" docblock at `registration.php:72-77`.
- Pin `@wp-playground/cli` and the blueprint's `wp` version.
- Add `trap 'rm -rf "$tmp"' EXIT` in `setup.sh:58`. Let `demo.sh` accept a port (it hard-codes 9400 while `start.sh` takes one).
- Reject `rest_route` in fence queries and add a test.
- Use `hourCycle: 'h23'` in `dates.js:230`.
- Extract `sma_save_draft_note()`.

## What's genuinely well done

- **The connector fence** (`wp.js`): allow-list plus deny-list plus body rules, with plain-English refusals that Claude can relay to Matt, and tests for each rule.
- **The diagnosis rule** is enforced by allow-lists (`sma_dash_child_field_names`, `sma_diagnosis_field_names`), not by convention. `error_log` never logs values.
- **Every admin action** checks the capability and a nonce. REST routes have permission callbacks. Output is escaped at the view, with notes where it's deliberately raw.
- **Small but right:** CSV formula-injection guard (`export.php:129`). Email lookups refused over GET (R23). Private meta hidden from public REST.
- **Preview then apply** is applied consistently across the connector tools.
- **Comments explain why** and cite Fluent source lines, which is exactly what an AI maintainer needs, once the versions are corrected.
- **Plugin PHP is 7.4-clean**, and `pack_kit.py` refuses to ship shell scripts with CRLF line endings.
- **`docs/review-findings.md`** tracks review items with R-numbers and fix status, which is a good habit to keep.
