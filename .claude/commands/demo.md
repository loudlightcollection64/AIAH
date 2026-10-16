---
description: Start the local demo site with fictional events, families and volunteers
---

Start the So Many Angels demo on this computer.

1. Tell Matt what will happen: a local copy of the website starts on this computer with
   fictional data only, and nothing touches somanyangels.org. The very first time it loads the
   demo data, which takes 15–20 minutes, and keeps a copy; after that it starts in a minute or
   two. If the script says it is loading the data from scratch, tell Matt the reason it printed
   (for example, the saved copy is more than 3 days old) and that it takes 15–20 minutes.
2. Run `./demo.sh` from the kit folder. Use `./demo.sh --reset` if $ARGUMENTS asks to start over
   or for clean demo data (it drops what was changed during an earlier demo), `./demo.sh --reseed`
   if it asks to reload the data from scratch, and add `--quick` if it says "quick". If setup is
   missing it runs `./setup.sh` first; if that reports something missing (Node.js, Python),
   explain in one sentence how to install it and stop.
3. When it finishes, run the connector's `check_connection` tool on the `sma-wordpress-local`
   server and confirm everything says ok. If the connector isn't available yet, tell Matt to
   restart Claude Code in this folder so it picks up `.mcp.json`, and approve the connector when
   asked.
4. Give Matt the three links the script printed (events page, dashboard, mail log) and the login
   (admin / password), and suggest a first try: "New event: Columbus, December 12, 10 to 12, Demo
   Hall, 12 sessions." Mention that `/demo` with "reset" puts the demo data back as it started.
