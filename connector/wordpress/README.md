# So Many Angels — Website connector

Lets Matt's Claude read the website's pages and create, update and publish **event records**.
Publishing an event makes the website itself set up everything else with its own permissions:
the booking sessions, the reminder emails, the CRM tag and the event page. Claude can't see,
contact or change any family.

## Why so little?

Round 1 of this connector also set up booking sessions, reminder campaigns and form dropdowns.
The security review (`docs/reviews/security.md`) showed the permissions that needed are
dangerous even if the connector itself behaves. "Manage Forms" lets any holder of the password
add a form notification that emails every future sign-up, diagnosis included, to anyone.
"Write and send emails" exposes campaign recipients. Booking permissions expose attendees.

So that work moved into the SMA Events plugin, which does it server-side when an event is
published. Claude's WordPress account now needs only the **SMA Agent** role: event records, no
Fluent permissions at all. A leaked password reaches no family data. Matt can also run an event
without AI: fill in the event screen and publish.

## Tools

| Tool | What it does |
|---|---|
| `check_connection` | Confirms the site answers |
| `list_pages` | Published pages with their links |
| `list_events` | Events with their details and, once published, whether booking is set up |
| `get_event` | One event and its set-up status |
| `save_event` | Previews, then creates or updates an event; `publish: true` opens booking |

## The fence

Every request passes `server/wp.js` before it is sent:
- **Only three routes:** `GET /wp/v2/pages`, `GET /wp/v2/sma-events[/id]` and
  `POST /wp/v2/sma-events[/id]`.
- **Plain paths only:** no encoding, dot segments, doubled slashes or backslashes.
- **Known query options only,** each once. This blocks a second `rest_route`, encoded or
  bracketed keys, and `_embed`.
- **Event bodies limited** to title, slug, status (draft or publish) and the event's own details.
  The booking and CRM fields are set by the site only.

`npm test` covers the fence, including the bypasses the security review tried, and the timeout.

## Running it

| Where | How |
|---|---|
| Local demo (Claude Code in the kit) | `.mcp.json` runs `local.mjs`, which connects as `claude-agent` (SMA Agent role) using a password it gets from the local site |
| Live site, Claude Desktop | Double-click `sma-wordpress.mcpb`: site address, `claude-agent`, its application password (kept in Claude's secure storage) |
| Live site, Claude Code | `security add-generic-password -s sma-wordpress -a claude-agent -w` once, then `claude mcp add sma-wordpress-live -- node connector/wordpress/live.mjs`. The password stays in the macOS Keychain, not a config file. A shell running as Matt can still ask the Keychain; the real protection is that the account itself can't reach family data. |

Revoking the application password in WordPress (Users → claude-agent → Application Passwords)
cuts every connection off immediately.

## Develop

```
npm install
npm test                 # fence rules and timeout, no site needed
node test/smoke.mjs      # every tool as claude-agent against the local site, plus a check that
                         # claude-agent's password is refused on every family-data route
npx @anthropic-ai/mcpb pack . ../../dist/sma-wordpress.mcpb
```
