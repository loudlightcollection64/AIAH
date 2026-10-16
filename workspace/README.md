# So Many Angels workspace

Everything Claude needs to know about So Many Angels, in plain files you can read and edit too.

| Folder / file | What's in it |
|---|---|
| `CLAUDE.md` | Claude's ground rules: never read family records, drafts first, ask before anything permanent |
| `vault/` | Notes about the organisation, how events run, your tools, and how you write |
| `runbooks/` | Step-by-step procedures, e.g. `new-event.md` |

## Two ways to use it

**1. Everyday: Claude Desktop chat** (setting up events, drafting emails)
- Create a Project called "So Many Angels".
- In the Project's instructions, paste the contents of `CLAUDE.md`.
- Add the files from `vault/` and `runbooks/` to the Project's knowledge.
- The website connector (`sma-wordpress.mcpb`) and Google Calendar are switched on in Claude's
  settings and work in any chat.
- Then just ask: "New event: Pittsburgh, November 14, 10 to 12, at the Cranberry studio, 20
  sessions." Claude follows `runbooks/new-event.md` and shows you each change before making it.

**2. Building and fixing things: Claude Code** (changing the website's code, new features)
- Open this folder in Claude Code. It reads `CLAUDE.md` and the vault automatically.
- The website connector is added to Claude Code separately; we'll set this up together.

## Keeping it current

When something changes (a new partner, a new email you like, a new step in how events run),
tell Claude: "Add this to the vault." The notes are plain text, so you can also edit them in any
text editor. If you change a note, update the Project's copy in Claude Desktop too.
