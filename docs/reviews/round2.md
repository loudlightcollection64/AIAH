# Round 2 review: security and requirements after fixes (15 Oct 2026)

Static review of `master`; the site was not run. Connector `npm test`: 50/50 pass.

## Previous findings: status now

| # | Status | Evidence |
|---|---|---|
| Sec C1 agent account over-privileged | **Partly** | Role trimmed to `read` plus 5 event caps (`event-record.php:129-149`). No Fluent caps. Agent 403 tests exist (`demo_e2e.py:19`). The password is in the Keychain (`connector/wordpress/README.md:50`), but Claude's own Bash can still read it. The account is still not limited to events: see N1. |
| Sec H1 approval advisory only | **Fixed** | The connector has no send tools. Family emails go out only through the dashboard confirm (`dashboard-actions.php:83`). Residual risk: N12. |
| Sec H2 fence bypass | **Fixed** | Query keys are `[a-z_]` only, allow-listed per route, and each may appear once (`wp.js:53-63`). Body keys are allow-listed (`wp.js:67-79`). |
| Sec M1 public form rewrites contact | **Partly** | The form now fills only empty fields (`stages.php:712-717`). But the entry is still linked and the stage still moves: N7. |
| Sec M2 diagnosis fails open | **Partly** | Fields are now blocked by label as well as name (`registration.php:85-200`). Every entry read goes through `sma_entries_read`. The pattern is narrow, and an unreadable form falls back to name-only blocking: N10. |
| Sec M3 child names in CRM | **Fixed** | Decline drafts are kept in an option (`approval.php:144-176`). CRM notes carry no names (`:279-283`). |
| Sec M4 editors get family data | **Fixed** | Off by default (`settings.php:25`), synced by `:155-166`. |
| Sec M5 dev mu-plugins | **Fixed** | Host guard `site/dev/mu/00-sma-dev-guard.php`, applied at `sma-dev-mail-log.php:7`. |
| Sec L1 unreviewed event text | **Partly** | Approve and thank-you emails are now previewed. The acknowledgement, booking confirmation and reminders are not: N9. |
| Sec L3 supply chain | **Fixed** | Pinned to `@wp-playground/cli@3.1.58` (`start.sh:32`), with SHA-256 checks (`setup.sh:65`). |
| Sec L4 CSV guard | Partly (harmless) | `\n` is still missing from the guard (`export.php:140`). `sanitize_text_field` strips newlines anyway. |
| Sec L2, L5 | Not re-verified | |
| Req 1/3/4 reminders | **Fixed** | Reminders are per booking in FluentBooking (`provisioning.php:435-461`), so they go to booked guests only. But they outlive cancellations: N2. |
| Req 2 no booking link | **Fixed** | Publishing provisions the booking session and page and sets `booking_url` (`provisioning.php:46-93`). |
| Req 6 form detection / settings | **Fixed** | `settings.php` has the form, calendar, minutes, reminder-day and editor settings. |
| Req 7 follow-ups | **Fixed** | Thank-you emails, previewed (`approval.php:333-366`, `dashboard-actions.php:107-142`). |
| Req 8 siblings/duplicates | **Fixed** | Sign-ups append and never replace (`registration.php:385-412`). A possible duplicate is flagged (`:541,555`). |
| Req 9 approve preview | **Fixed** | `dashboard-actions.php:77-85`. A send without `confirmed=1` is refused. |
| Req 10 platform comparison | **Fixed** | `REPORT.md:49`. |
| F2 wp-admin event form | **Fixed** | Details box (`event-record.php:454-572`). |
| F4 portfolio/location on live form | **Not met** | The live form has neither field (`form-fields.php:104-106`). REPORT should say so. |
| Sheets / Airtable | Partly | CSV export only. Airtable is stated as open (`REPORT.md:129-130`). |
| Mobile, Mac first run | Not re-verified | |

## New findings (ranked)

### N1 HIGH: the SMA Agent can point provisioning at any page or booking type (CONFIRMED by trace, not executed)
- **Where:**
  - Every key in `SMA_EVENTS_META` is REST-writable for anyone who has `edit_sma_events` (`sma-events.php:36-53`, `event-record.php:32-45`). That includes `booking_page_id`, `booking_session_id`, `booking_calendar_id`, `crm_tag_id`, `booking_url` and `sma_provision_error`.
  - Provisioning trusts those keys.
- **Scenario:** using the app password and plain `curl` (no connector), send `POST /wp/v2/sma-events/7 {"meta":{"booking_page_id":<home or donate page id>}}` to a published event.
  - `sma_provision_booking_page` (`provisioning.php:493-511`) rewrites that page. Its title becomes "Book your session: X", its content becomes the shortcode, and it is published. Setting the event to draft then unpublishes the page (`:570-576`).
  - With `booking_session_id=<any FluentBooking event>`, the same thing happens to booking types. Matt's own booking type gets new availability, location, emails and `status=active` (`:406-417`). If that booking type is in another calendar, it is set to draft (`:360-364`).
  - A `booking_url` the agent sets survives whenever provisioning stops before the page step (bad date, draft event). It then becomes the link in approval emails.
- **Fix:**
  - Make the six site-owned keys read-only over REST: an `auth_callback` that refuses writes, or move them to protected `_sma_*` keys exposed through a read-only `register_rest_field`.
  - In provisioning, check ownership. A page counts only if its `_sma_booking_page_event` equals this event's ID. A session counts only if session meta names this event.
  - Add a `demo_e2e` case where the agent writes these keys and gets 403.

### N2 HIGH: cancelled events and declined families still get "see you soon" reminders (CONFIRMED)
- **Where:**
  - `sma_unprovision_event` sets only the session and page to draft (`provisioning.php:554-578`).
  - `sma_decline_family` never touches bookings (`approval.php:243-286`).
  - FluentBooking's reminder job checks only `booking.status == scheduled` (`fluent-booking/.../NotificationHandler.php:165-169`).
- **Scenario:** a venue falls through, so Matt sets the event to draft. Every booked family still gets the 3-day and 1-day reminders. The same happens to a family declined after booking through a forwarded link.
- **Fix:**
  - On unpublish, count the scheduled bookings. Warn Matt, and offer to cancel them with a previewed message.
  - On decline, cancel the booking, or `as_unschedule_all_actions('fluent_booking/booking_schedule_reminder', [id,'guest'])`.

### N3 HIGH: updating an event through the connector unpublishes it by default (CONFIRMED)
- **Where:** `events.js:92,99`. `publish` defaults to false, so every update sends `status: 'draft'`.
- **Scenario:** Claude fixes a venue typo on a live event. Booking is closed, the booking page is unpublished (N2 applies), and approved families' links stop working. The preview says only "Saved as a draft: nothing is visible to families yet."
- **Fix:**
  - On update, leave out `status` unless it was asked for.
  - Make the preview show the current status and the new one, and flag "this closes booking".

### N4 MEDIUM: date or time changes strand existing bookings (CONFIRMED by absence)
- **Where:** the session is rewritten in place (`provisioning.php:406-417`). Existing bookings, and their scheduled reminders, keep the old time. Nothing warns anyone.
- **Fix:**
  - When date, time or zone changes and bookings exist, show a notice.
  - Through the connector, refuse the change unless Matt confirms.

### N5 MEDIUM: the booking page URL is guessable (CONFIRMED)
- **Where:** the page slug is `book-<event slug>` (`provisioning.php:518`), and the event slug is public at `/event/<slug>/`.
- **Scenario:** anyone can book without approval and fill the per-day cap. They also receive the confirmation and reminders. A flag is raised only if their email is already a contact.
- **Fix:**
  - Use a random page slug.
  - Add a FluentBooking pre-booking check that refuses emails not approved for the event.

### N6 MEDIUM: bookings are matched to the wrong event (CONFIRMED trace)
- **Where:**
  - `sma_event_for_booking` falls back to `booking_calendar_id` (`approval.php:409-426`).
  - All events share the Settings calendar, or the first host calendar (`provisioning.php:225-249`).
- **Scenario:** a booking on Matt's own booking type in that calendar, or on a drafted event's session, is attributed to the first published event in the calendar. A tagged contact then moves to booked or needs-follow-up there.
- **Fix:** match on session ID only.

### N7 MEDIUM: a sign-up using someone else's email still changes their record (CONFIRMED)
- **Where:** `registration.php:561-567`.
- **Scenario 1:** the form is submitted with a booked family's email and a different guardian name. The entry is still linked, the event's stage drops to needs-follow-up, and the stage tag moves, which affects automations.
- **Scenario 2:** with the right guardian name, which is not secret, the attacker's "child" joins the family's registration. That child is named in the approval email (`approval.php:113-122`).
- **Fix:**
  - Hold name-differs entries apart from the family's list.
  - Never downgrade a confirmed family's stage.
  - Leave flagged entries out of email names.

### N8 MEDIUM: every save reopens what Matt closed (CONFIRMED)
- **Where:** each save of a published event does all of these:
  - sets the session `active` (`provisioning.php:408`);
  - overwrites the confirmation and reminder subject and body (`:450-457`);
  - republishes a booking page Matt had drafted (`:500-505`).
- **Scenario:** a routine agent edit reopens a session Matt closed because it was full, and wipes his hand-edited wording.
- **Fix:** activate and overwrite only on the transition to publish, or when the plugin's own values changed.

### N9 LOW: agent-written text reaches families without review (CONFIRMED)
- **Where:**
  - City, venue and address become the FluentBooking title and location (`provisioning.php:186-199,350-354`). From there they go into the confirmation and reminders, and the event label goes into the automatic acknowledgement.
  - A writable `sma_provision_error` shows any text the agent chooses as an admin notice (`event-record.php:625-627`).
- **Fix:** make the `save_event` preview show the rendered family emails. N1 fixes the notice.

### N10 LOW: the diagnosis label pattern is narrow and fails open (CONFIRMED)
- **Where:** `/diagnos|medical|condition|illness/` (`registration.php:87`).
  - It misses "Type of cancer", "Treatment", "Oncology" and "What is your child fighting?".
  - When the form structure can't be read, only names are blocked, and the event is only logged (`:189-193`).
- **Fix:**
  - Widen the pattern: `cancer|treat|oncolog|tumou?r|leuk|health|hospital`.
  - For the sign-up form, return no child fields when the structure is unreadable.

### N11 LOW: uninstall and hard delete leave booking open (CONFIRMED)
- **Where:**
  - `uninstall.php:16-18` leaves sessions active and pages published. It also removes the noindex and listing filters, so the pages become discoverable.
  - `:56` silently deletes unsent decline drafts.
  - A permanent delete without trash fires no `transition_post_status`, so the session stays live.
- **What uninstall keeps:** it does not delete families' data (entries, contacts, notes, bookings). That part is correct.
- **Fix:**
  - On uninstall, and in `before_delete_post`, set the sessions and pages to draft.
  - Warn Matt before uninstall deletes drafts.

### N12 LOW: two send paths have no preview (CONFIRMED)
- **Where:**
  - `POST /sma/v1/families/approve` (`rest.php:96-104`) emails the booking link straight away. It is admin-only, but any admin app password used in a script or by Claude can email families unreviewed.
  - Volunteer approve also sends without a preview (`dashboard-actions.php:186-187`).
- **Fix:** restrict the REST route to the test environment, or require the same `confirmed` step.

## Holding
- Public templates escape every event field (the only raw output is Fluent's own form or theme markup).
- `/event/<slug>/` shows published events only (`pages.php:149`).
- Fluent's own option validation (`Helper.php:1192-1248`) means the public form can't sign up for a draft event.
- Nonces and capability checks are on every admin-post handler. All sma/v1 routes require `sma_manage_families`.
- Settings are `manage_options` only.
- Reminder emails are sent only for scheduled bookings.
