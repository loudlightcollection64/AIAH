# Public URLs for events

Decision (round 2, P4): **one event lives at `/event/<slug>/`** (singular). The events list is at
`/events/` only when the site has no page of its own there; otherwise Matt puts the list on his
own `/events/` page with the `[sma_events]` shortcode or the "SMA upcoming events" block.
`?sma_event=<slug>` always works as a fallback.

## Why not `/events/<slug>/`

somanyangels.org already has a page at `/events/` (page 241, read with a public GET on
14 Oct 2026). If events also lived at `/events/<slug>/`, any child page Matt makes under it
(`/events/faq/`) would break:

(Line numbers: WordPress core `master`, read 14 Oct 2026.)

- WordPress tries rewrite rules in order and stops at the first match. Custom post type rules
  (`add_permastruct()` and `add_rewrite_rule(..., 'top')`) go into `extra_rules_top`, which comes
  **first**; page rules come near the end (`WP_Rewrite::rewrite_rules()`,
  `wp-includes/class-wp-rewrite.php:1453-1460`; `WP_Post_Type::add_rewrite_rules()`,
  `class-wp-post-type.php:719, 732`).
- So `/events/faq/` would match the event rule `events/([^/]+)...` and look for an *event* called
  `faq`: a 404 instead of Matt's page. The extra "is this really a page?" check in `WP::parse_request()`
  (`class-wp.php:243-245`) applies only to page rules, never to post type rules.

`/event/<slug>/` can only shadow children of a page called `event`, and the live site has none.

## What resolves where

| URL | No `events` page on the site | Matt's `events` page exists (live site) |
|---|---|---|
| `/events/` | the plugin's list (upcoming, soonest first) | Matt's page; the list appears where he puts `[sma_events]` |
| `/events/page/2/` | 404 | Matt's page (WordPress ignores the page number) |
| `/events/<child-page>/` | that page, if one exists | Matt's child page |
| `/event/<slug>/` | the event page with the sign-up form, event preselected | same |
| `/event/<slug>/` for a past event | "This event has passed" (200, noindex) | same |
| `/event/<slug>/` for a draft | 404 | 404 |
| `/events/<slug>/` (old round 1 link) | 301 to `/event/<slug>/` if published, else 404 | same |
| `?sma_event=<slug>` | the event page (any permalink setting) | same |
| `?post_type=sma_event` | the plugin's list | 404 (no second, theme-styled list) |

"Matt's page exists" means a top-level page with the slug `events` in any status except trash, so
a draft of it already switches the plugin's list off. The setting **So Many Angels → Settings →
public events list** switched off has the same effect. The "See all upcoming events" links on
event pages go to Matt's published `/events/` page, else to the plugin's list, else are hidden.

## How it works

- `sma_pages_post_type_args()` (`includes/pages.php`, core filter `register_post_type_args`) sets
  the rewrite slug to `event` and registers the archive only when `sma_pages_archive_on()`.
- Rules are flushed lazily on `init` when the stored key (`sma_pages_rewrite_version` option,
  rule version plus the archive decision) changes, so adding, publishing, trashing or deleting the
  `events` page, or flipping the setting, takes effect on the next page load. If the `/event/`
  rule is missing from the stored rules (first activation, or permalinks saved while the plugin
  was off) they are rebuilt, at most once an hour.

## What Matt has to do

Nothing, if he keeps his `/events/` page: edit it in Elementor or the block editor and add the
shortcode where the list should appear:

```
[sma_events]
[sma_events limit="3" heading="Coming up"]
[sma_events show_past="1"]
```

(`limit`: how many, default all; `show_past`: also list past events after the upcoming ones;
`heading`: an optional heading above the list.) In the block editor he can instead add the
"SMA upcoming events" block, which has the same three settings. If he would rather have the
plugin's list page, he deletes (trashes) his `/events/` page and leaves the setting on.
