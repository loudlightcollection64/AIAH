---
description: Walk Matt through putting the plugin on the live website, safely
---

Matt wants to put the SMA Events plugin (or a new version of it) on somanyangels.org. This
changes the live site, so go one step at a time and wait for Matt to confirm each step.

1. **Check it's ready:** `git status` is clean, and `/test` passed on the current version. If
   not, stop and say so.
2. **Back up first:** Matt makes a backup in the IONOS control panel (WordPress → Backups, or
   his plan's equivalent) and confirms it finished.
3. **Build the zip:** `cd plugin && zip -r ../dist/sma-events.zip sma-events -x '*.DS_Store'`
   (create `dist/` first). Tell Matt where the file is.
4. **Upload:** WordPress → Plugins → Add New → Upload Plugin → choose the zip → Install. On an
   update, WordPress asks to replace the current version: yes. Then Activate.
5. **Check the sign-up form settings** (Fluent Forms → the session sign-up form):
   - the admin notification email must not include the Diagnosis field;
   - no FluentCRM integration feed may map child fields (diagnosis, age, child's name) into
     contacts.
6. **Settings:** So Many Angels → Settings. Matt chooses his session sign-up form, volunteer
   form and booking calendar, checks the session length (10 minutes) and reminder days (14, 7,
   3, 1), and decides whether the plugin's events list replaces his existing `/events/` page or
   goes on it with the `[sma_events]` shortcode.
7. **Claude's account:** WordPress → Users → Add New, `claude-agent`, role **SMA Agent** (the
   plugin adds it), **no** Fluent Forms, FluentCRM or FluentBooking permissions. Then an
   application password for it (Users → claude-agent → Application Passwords). See REPORT.md,
   "Going live".
8. **Try it once:** Claude saves a fictional event as a draft. Matt publishes it, checks it says
   "Ready", signs up one obviously fictional family, approves them on the dashboard and books a
   time. Then he deletes the test event, contact, form entry and booking himself.

If anything looks wrong at any step, stop, say what happened, and suggest deactivating the
plugin (Plugins → Deactivate), which undoes its effect without losing data.
