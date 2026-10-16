---
description: Match the local copy's plugin versions to the live site after Matt updates plugins
argument-hint: "[slug=version ...]"
---

Matt updated (or wants to update) Fluent Forms, FluentCRM, FluentBooking, Elementor or the Solace
theme on somanyangels.org. The local copy must run the same versions, so changes are tested
against what the live site really runs.

1. **Find the live versions.** If $ARGUMENTS gives them (e.g. `fluentform=6.2.16`), use those.
   Otherwise ask Matt to read them from WordPress → Plugins on the live site (the free plugin's
   version, not the Pro add-on's), or from Tools → Site Health → Info. Show him what is pinned
   now: `python3 -I tools/update_plugins.py --check`.
2. **Pin them:** `python3 -I tools/update_plugins.py <slug>=<version> ...`. It downloads each zip
   from wordpress.org and records its fingerprint in `setup.sh`.
3. **Download:** `./setup.sh`.
4. **Reload the demo data:** `./demo.sh --stop`, then `./demo.sh`. The versions changed, so it
   loads the demo data again (15–25 minutes); say so before starting.
5. **Test everything:** run the full test (`/test full`). Check the PHP debug log for new
   warnings from our plugin.
6. **Report in plain words:** what passed, and anything that broke. If something broke, the
   live site may be affected too: say which feature, and propose a fix on the local copy first.
7. **Update the version numbers** written in `CLAUDE.md` (rule 5) and `REPORT.md`, then commit:
   "Pin <plugin> <version> (matches the live site)".

Never update plugins on the live site from here. Matt does that in WordPress, ideally after a
backup and after this check passed on the new version.
