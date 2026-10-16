---
description: Run the automated checks (the quick core check; "/test full" runs everything)
argument-hint: "[full]"
---

Run the checks and report the result in a few lines. If the argument is `full` ($ARGUMENTS),
run the full acceptance test in step 3; otherwise run the quick check.

1. **Connector rules** (fast, no site needed): `cd connector/wordpress && npm test`.
2. **Make sure the local site is running and seeded:**
   `curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:9400/` should print 200. If not,
   run `./demo.sh` and wait. For a clean start (the demo data as seeded, nothing left from an
   earlier demo or test run), run `./demo.sh --reset`: it restores the saved seeded copy in a
   minute or two instead of seeding again. The checks expect the full demo data, so don't use
   `--quick` here.
3. **The acceptance test**, from the kit folder. Exit code 0 means every check passed. A
   progress line per finished part goes to the error output; the full report comes at the end.
   - **Quick (default):** `python3 -I site/dev/demo_e2e.py --quick`. Matt's core path:
     Claude's account publishes an event and the site sets up its booking page and link; a
     fictional family signs up through the real form; Matt approves from the dashboard (the
     email preview, then the booking-link email in the mail log); the family books a time
     through FluentBooking; the dashboard shows them booked with reminders scheduled; Claude's
     account is refused every family-data route; no diagnosis in any email, CSV or dashboard
     view; the PHP debug log stays clean. 94 checks, at most about 9–11 minutes on a seeded
     site.
   - **Full (`/test full`):** `python3 -I site/dev/demo_e2e.py`. Everything above plus
     volunteers, declines, thank-yous, siblings and duplicates, the medical-label rule,
     settings, digest, site checks, nudges, attendance, canvases, public URLs and event changes;
     over 220 checks, about 25–30 minutes. Run it in the background.
   Both run independent parts at the same time (the local site answers several requests at
   once); `--serial` runs one part at a time if you need to rule that out.
4. **The connector walkthrough** (if anything in `connector/` changed):
   `cd connector/wordpress && node test/smoke.mjs`. It runs every tool as `claude-agent`, and
   checks that account is refused on every family-data route.

Report how many checks passed and which test ran (quick or full). For any failure, say what it
means in plain words and what you propose to fix. Don't print family-like data from the site;
use counts and fictional markers only. If a single step times out, run it again once before
debugging: the local site sometimes drops a request. The local site is much slower when a
laptop runs on battery power, so keep it plugged in for a long run.
