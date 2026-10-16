---
title: Monthly maintenance runbook
type: runbook
tags: [runbook, maintenance]
status: 16 Oct 2026
---

# Monthly maintenance (about 30 minutes)

Use once a month, or when Matt says "let's do the website check". Steps 1 and 3–5 are Matt's,
in WordPress; Claude walks him through them and does steps 2 and 6 in this folder.

1. **Backup:** check the latest IONOS backup exists and is recent.
2. **Plugin versions:** `python3 -I tools/update_plugins.py --check` shows the pinned versions
   and the newest ones. If Matt wants to update:
   - `/update-plugins` with the new versions → `/test full` on the local copy;
   - if it passes, Matt updates those plugins on the live site (after the backup);
   - if it fails, don't update the live site; fix on the local copy first.
3. **Site checks:** So Many Angels → Settings → Site checks. Anything marked "warn" gets a
   sentence of explanation from Claude and a plan.
4. **Dashboard sweep:**
   - families waiting for approval longer than the nudge days;
   - booked families with "No reminders scheduled";
   - canvases not yet delivered (Follow-ups → Canvases);
   - past events with booked families not yet marked attended.
5. **Accounts:** WordPress → Users. Remove anyone who no longer helps; check `claude-agent` is
   still role "SMA Agent" only; revoke application passwords that are no longer used.
6. **Records:** apply the data-retention rule Matt chose (`vault/org/data-retention.md`), and
   note anything learned this month in the vault ("Add this to the vault").

## Relations
- follows [[Going live]]
- uses [[Data retention]]
