---
title: Site health
type: note
tags: [tools, security]
---

# Site health

Issues found on 13 Oct 2026, in priority order. Fix on a staging copy first where possible.

## Observations
- [security] Debug output is shown to visitors: `WP_DEBUG` and `WP_DEBUG_DISPLAY` are both true on the live site. Any PHP error prints file paths on the page. Turn display off.
- [security] PHP 7.4.33 has had no security fixes since November 2022. Upgrade in the IONOS panel after testing plugins on staging.
- [security] Wordfence is installed but inactive
- [security] Anyone can list the site's three accounts at `/wp-json/wp/v2/users` (checked 15 Oct 2026). WordPress does this by default for users who have published posts, and the listed name is usually the login name, so it hands a password guesser half the login. Low risk on its own, since Limit Login Attempts is active. Fix: turn on two-factor login for administrator accounts (most important, since the dashboard holds family data), and hide the users list from visitors (Wordfence has a setting for it).
- [maintenance] Most plugins are behind and have auto-updates off, including the whole Fluent suite and FluentSMTP
- [scheduling] Site timezone is a fixed offset (`-04:00`), not a city. Scheduled times will be an hour wrong after clocks change on 1 Nov 2026. Set it to America/New_York. Events in Central time (Kansas City, Indianapolis) need their own handling.
- [storage] WordPress reports 26.9 GB total but uploads, plugins and themes add up to about 1.4 GB. Roughly 25 GB is unexplained; often old backup archives in the web folder. Check whether any are publicly downloadable.
- [hosting] The site path ends in `Restore`, so it was restored from a backup at some point

## Relations
- part_of [[Tool stack]]

## Sources
- WordPress Site Health report, 13 Oct 2026
