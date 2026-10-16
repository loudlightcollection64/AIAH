# So Many Angels kit

Everything for running and growing So Many Angels' event system with Claude: the website plugin,
a safe connector for Claude, a knowledge base about how So Many Angels works, and a local copy
of the website to try things on.

**Start here:** read [REPORT.md](REPORT.md) for what this is and why.

## Try it (about 30 minutes the first time)

**Installing on a Mac:** with the kit's zip in Downloads, paste this into Terminal. It asks where
to keep the kit, installs anything missing (including Claude Code) and starts the demo:

    cd ~/Downloads && (unzip -oq so-many-angels-kit.zip 2>/dev/null; bash so-many-angels-kit/install.sh)

Once it's installed:

1. Open this folder in **Claude Code**.
2. Type **`/demo`**. Claude checks your Mac, sets things up and starts a local copy of the
   website with fictional families. If something needs installing (Node.js or Python), it tells
   you how.
3. Open the links Claude gives you, then ask it: *"New event: Columbus, December 12, 10 to 12,
   Demo Hall, 12 sessions."*

Nothing here touches somanyangels.org, and no real email is sent.

Without Claude Code, the same thing from Terminal: `./setup.sh`, then `./demo.sh`.

The first `/demo` loads the fictional data (15–20 minutes) and keeps a copy; later starts take two
or three minutes. `./demo.sh --reset` puts the demo data back as it started; `./demo.sh --help`
lists the rest.

## What's inside

| Folder | What it is |
|---|---|
| `plugin/sma-events/` | The WordPress plugin: events, sign-up → approval → booking, volunteers, dashboard, CSV export, event pages, emails |
| `connector/wordpress/` | Lets Claude set up events on the site; it can never see family records |
| `workspace/` | Knowledge about So Many Angels, Claude's operating rules, and step-by-step runbooks |
| `site/` | The local copy of the website, demo data and automated tests |
| `docs/` | How it was built: plan, build log, review findings, demo script, design notes |
| `CLAUDE.md` | What Claude Code reads first: how the system works and the rules |

## Commands in Claude Code

- `/demo`: start the local copy with fictional data
- `/new-event`: set up an event, previewing each step for your approval
- `/test`: check everything still works
- `/ship-plugin`: put the plugin on the live site, step by step, with a backup first
- `/update-plugins`: after updating plugins on the live site, match the local copy and test

Going live: `workspace/runbooks/going-live.md`. Monthly upkeep:
`workspace/runbooks/monthly-maintenance.md`.
