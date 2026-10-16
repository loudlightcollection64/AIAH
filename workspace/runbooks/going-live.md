---
title: Going live runbook
type: runbook
tags: [runbook, live-site, security]
status: 16 Oct 2026
---

# Going live

Use when Matt is ready to put the system on somanyangels.org. Every step changes the live site,
so do one at a time, say what it does first, and wait for Matt's yes. Matt clicks; Claude
explains. After installing the plugin, So Many Angels → Settings → Site checks shows which of
these are still open.

## Before the plugin (safety)
1. **Back up the site** in the IONOS control panel and confirm the backup finished.
2. **Timezone:** WordPress → Settings → General → Timezone → choose **New York** (a city, not
   "UTC-4"). With a fixed offset, booking times and reminders shift by an hour when clocks
   change (1 November 2026).
3. **Two-factor login** for every administrator account (the dashboard will hold children's
   medical sign-ups). Wordfence is already installed but inactive: activate it and turn on its
   two-factor login, or install the "Two-Factor" plugin. Each administrator sets it up on their
   own profile with an authenticator app.
4. **Hide error messages from visitors:** ask IONOS support (or the hosting panel) to turn off
   "display errors" / `WP_DEBUG_DISPLAY`. Errors still go to the log.
5. **Email that arrives:**
   - WordPress → FluentSMTP: check a connection is set up (ideally Google Workspace or a mail
     service, not "PHP mail"), and send FluentSMTP's test email to Matt's own address.
   - In the IONOS DNS panel, check the domain has SPF and DKIM records for whatever FluentSMTP
     sends through (FluentSMTP and the mail provider show the exact records). The domain's DMARC
     is "p=none" (monitor only); leave it until SPF and DKIM pass, then consider "quarantine".
   - The first real test sign-up (step 9) goes to Matt's own address: check it lands in the
     inbox, not spam.

6a. **Scheduled jobs on time:** reminders and the daily digest run through WordPress's job runner
   (WP-Cron), which only runs when someone visits the site. Ask IONOS support (or the hosting
   panel's cron jobs) to call `https://somanyangels.org/wp-cron.php` every 5 minutes, then add
   `define('DISABLE_WP_CRON', true);` to `wp-config.php` (IONOS support can do this). FluentBooking
   recommends the same.

## Installing
6. **The plugin:** follow `/ship-plugin` (build the zip, upload, activate, settings, Claude's
   account). It includes checking the sign-up form's own notification and CRM feeds for the
   diagnosis field.
7. **Menu:** Appearance → Menus (or the Elementor header): add **Events** pointing at `/events/`.
8. **Volunteer form (optional):** add the portfolio and location fields Matt wanted (Fluent
   Forms → the volunteer form); the plugin's field names are in `site/dev/live_form.py`.

## First run
9. **One fictional event end to end:** publish it, sign up an obviously fictional family with
   Matt's own email, approve, book. Check the confirmation, the "pick your time" email and the
   booking confirmation arrive. Then delete the test event, contact, form entry and booking.
10. **Daily digest:** Settings → "Send a test digest now", and check it arrives.

## Later
- **PHP:** the site runs PHP 7.4 (no security fixes since 2022). Plan the upgrade to 8.1 or
  newer in the IONOS panel, after updating plugins and running `/test full` on the local copy.
- **Plugin updates:** update on the live site after a backup, then run `/update-plugins` so the
  local copy matches.

## Relations
- follows [[Site health]]
- part_of [[Monthly maintenance]]
