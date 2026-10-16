# So Many Angels: what we built and how to use it

For Matt Meiers, from the AI in Action Hackathon team, October 2026.

## In one paragraph

You asked for one simple system to replace your forms, spreadsheets, scheduling tools and manual
emails. We found you already own almost all of it: WordPress, Fluent Forms Pro, FluentCRM Pro,
FluentBooking Pro and Google Workspace. They just aren't connected, so **you are the glue**, by
hand, for every event. We connected them with one WordPress plugin. Publishing an event now sets
up its sign-up option, booking times, reminder emails and event page by itself. Your own Claude
has a written knowledge base about So Many Angels and a safe way to work on your site. The
result runs on your Mac today with made-up families, and goes onto your real site whenever you
say so.

## What we found

- **Every event is assembled by hand:** a hand-built page, a hand-typed dropdown in the sign-up
  and volunteer forms, and hand-sent emails. Your live sign-up form and events page still
  advertise events that have already happened.
- **The tools you pay for can already do most of the work.** FluentBooking can send each family
  reminders before their own session. They weren't wired into your process.
- **A few things on the site need attention,** whatever you decide about the rest:
  - error messages are switched on for visitors;
  - PHP is version 7.4, which stopped getting security fixes in 2022, with its speed cache off;
  - most plugins are behind on updates;
  - the timezone is "-04:00" instead of "New York", so times shift after November 1;
  - about 25 GB of the hosting space is unexplained, often old backup files;
  - three account names are publicly listed.

  Details are in `workspace/vault/tools/site-health.md`.

## What we built

| What you asked for | What it does now |
|---|---|
| **Event management** | Fill in an event: city, venue, date, times, number of sessions, how the canvas is delivered. You can do this in WordPress (on your phone too), or ask Claude to draft it for you. When you publish it, the site creates the booking times for that day, turns on the reminders, adds the event to your sign-up and volunteer forms, and shows its public page. Past events drop off by themselves. |
| **Family registration** | Families sign up through your existing Fluent form, unchanged. Each child is a separate sign-up, even siblings. A second sign-up for the same child and event is flagged as a possible duplicate. A family that's already in your CRM keeps their details; a mismatch is flagged for follow-up. Each family gets a "we've received your information" email. |
| **Approval** | From the dashboard. You see the exact email before it goes, then one click sends the link to pick a session time. Declines are written as a draft for you to edit and send yourself. If a declined or canceled family had already booked, their booking is cancelled too: the preview tells you first, and FluentBooking sends its cancellation note. |
| **Changing an event** | Taking a published event back to draft closes booking and pauses reminders for families who already booked. Their bookings stay, and the dashboard warns you. Moving the date or time flags the families booked at the old time for follow-up; nothing is moved or cancelled behind your back. |
| **Booking and reminders** | Approved families pick a time in FluentBooking. They get a confirmation and reminders 14, 7, 3 and 1 days before **their own session**, in your voice. Only families who booked get reminders, including anyone who books late. |
| **Thank-yous and follow-ups** | After an event, the dashboard offers thank-you emails for families who attended and volunteers who helped. You preview them, then send. |
| **Nudges and attendance** | A family waiting for your approval longer than 3 days says so on its row ("Waiting 5 days"), and the events list counts them ("2 waiting over 3 days"). On the day of an event or after it, tick the families who came and mark them all attended in one click; nothing is emailed, and thank-yous still wait for your preview. |
| **Canvas tracking** | Each attended family's canvas shows where it is: photos edited, sent to print, delivered (shipped or presented), with dates. One click moves it on. Follow-ups → Canvases lists every canvas not delivered yet, oldest event first, and the CSV has the canvas columns. Families get no emails about it. |
| **Volunteer applications** | Volunteers apply through your form. You approve them per event, and they get an email saying where, when and what to bring. Portfolio and location questions are ready to add to your form when you want them. |
| **Admin dashboard** | Every event, every family's stage (awaiting approval, approved, booked, attended, needs follow-up, canceled), the dates each booked family's reminders go out, each family's photo and story permissions, and the volunteers. A CSV export opens in Google Sheets. |
| **Settings** | One screen: which forms and booking calendar to use, session length, reminder days, whether the plugin's events list replaces your `/events/` page or goes on it (with the `[sma_events]` shortcode), and whether editors may see family data. By default only administrators can. |
| **Daily digest** | A short email from your website each morning (7:00 by default) on days when something needs you: families waiting for approval per event (and how many have waited more than 3 days), events in the next three weeks with sessions booked and volunteers approved, anything that needs a look (a set-up problem, booked families with no reminders waiting), and new volunteer applications. Counts and volunteer first names only, never a child's details; the dashboard link has the rest. Turn it off, change the time or the address on Settings, and use "Send a test digest now" to see today's. Written by the site, not by an AI. |
| **Site checks** | A list at the bottom of Settings of things to fix on your site, each with one sentence on how: the time zone set as an offset instead of "New York", error messages shown to visitors, an old PHP version, FluentSMTP not set up, your sign-up form's own admin email including the diagnosis, no "Events" link in the menu, how many administrator accounts there are, and whether two-factor login is on. It only reads; it changes nothing. |

Emails follow your writing style: warm, short, first names, "Thank you!". The event pages use your
site's colours, fonts and logo.

We also compared the other routes your brief mentioned (Softr, Replit, Bubble, Lovable, and
configuring your Fluent plugins with no custom code). See `docs/options.md`.

## How you'd use it day to day

- **Without AI:** WordPress → Events → Add New, fill in the details, publish. Approve families on
  the So Many Angels dashboard.
- **With Claude:** say *"New event: Pittsburgh, November 14, 10 to 12, at the Cranberry studio,
  20 sessions."* Claude drafts it, shows you, and publishes it when you say yes. It can also
  write announcements, partner emails and posts in your voice for you to send.

Either way, the routine sending is done by your website, not by an AI.

## Keeping families safe

- **Claude can't see families at all.** Its WordPress account can manage event records and
  nothing else. No forms, contacts, bookings or emails, so even a leaked password wouldn't
  expose anyone. The connector it uses enforces the same limit. Automated tests check both.
- **Your plugins' built-in AI connections are a risk.** FluentCRM's can list, read, delete and
  email every contact. We don't use them.
- **The diagnosis never leaves the form entry.** It's kept out of the CRM, emails, the dashboard,
  the CSV and logs.
- **Nothing reaches a family without you seeing it first,** except the automatic "we've received
  your information" note, the booking confirmation and the reminders for a session they booked.
- **The website's set-up details are locked.** The booking page and booking session behind each
  event can only be set by the website itself, not by Claude or anyone editing an event, so
  publishing can never be pointed at another page.
- **Everything we built and tested used made-up families.**

## Try it on your Mac

You'll need Claude Code (in your Claude plan; if it isn't available, your seat type may need
changing) and about 30 minutes the first time.

1. With the kit's zip in Downloads, paste this line into Terminal:
   `cd ~/Downloads && (unzip -oq so-many-angels-kit.zip 2>/dev/null; bash so-many-angels-kit/install.sh)`
   It asks where to keep the kit, installs anything your Mac is missing (including Claude Code),
   and opens Claude Code in the kit.
2. Claude runs `/demo`, which starts a private copy of your website on your Mac with fictional
   families. The first time, loading the fictional data takes 15–20 minutes; the copy is kept,
   so later, when you open the kit folder in Claude Code and type `/demo` yourself, it starts in
   two or three minutes. Ask for "a reset" to put the demo data back as it started. After 3
   days, or when the plugin changes, it loads fresh data again by itself.
3. Open the links it gives you: the events page (what families see), the dashboard (what you
   see) and the mail log (every email the site "sent"). Log in as admin / password.
4. Ask Claude: *"New event: Columbus, December 12, 10 to 12, Demo Hall, 12 sessions."*

Nothing in the demo touches somanyangels.org, and no real email is sent. The private copy is
slower than your real site: each page takes several seconds there, and about a second on your
hosting.

## Building with Claude Code

The kit is ready for you to keep building. Claude reads `CLAUDE.md` in the folder, which explains
the system, the rules (no real family data, never the diagnosis, the live site only changes when
you say) and how to test. You don't need to read code. Describe what you want, look at the result
on the private copy, and say yes or no.

- `/test` checks the core of what you do (Claude publishes an event, a family signs up, you
  approve, they book) in about 10 minutes on the private copy. `/test full` runs all 220-plus
  checks in about 25 minutes; start it and carry on with something else.
- `/new-event` sets up an event with Claude.
- `/ship-plugin` walks you through putting the plugin on your real site, with a backup first.

Every change is saved in the folder's history, so anything can be undone.

## Going live, when you're ready

The full step-by-step is `workspace/runbooks/going-live.md`; Claude can walk you through it. In short:

1. **Back up the site in IONOS.**
2. **Install the plugin** (`/ship-plugin` walks you through it) and fill in So Many Angels →
   Settings. Work down the **Site checks** list at the bottom of that page (time zone,
   error messages, FluentSMTP, the sign-up form's admin email, two-factor login), then click
   **Send a test digest now** and check it arrives.
3. **Check your sign-up form's own settings:** the admin notification email and any CRM feed must
   not include the diagnosis.
4. **Create Claude's account,** `claude-agent`, with the "SMA Agent" role and nothing else, plus
   an application password. Connect Claude with `sma-wordpress.mcpb`.
5. **Add "Events" to your site menu** (Appearance → Menus, or your Elementor header), pointing at
   `/events/`. Your menu has no link to events today, so families only find one through a
   home-page button. The demo copy shows it after "Home".
6. **Run one fictional event and sign-up end to end,** then delete them.

We tested everything on your exact plugin versions (Fluent Forms 6.2.5, FluentCRM 3.1.8,
FluentBooking 2.2.0). We also tested on copies of your real sign-up and volunteer forms, field
for field, read from your public pages. Your "Individual Session — Contact Us" and "No specific
date" options stay; only the hand-typed dated events are replaced. If you delete a field from
either form and add it again, its internal name changes, so ask Claude to re-check.

## What it costs

Nothing new. You already have Claude (Team plan) and every plugin we used. Node.js and Python are
free.

## Limits and what's next

- **Google Sheets and Airtable.** Exports are CSV files you open in Sheets. Air WP Sync (Airtable)
  is active on your site, but we didn't connect Airtable; tell us what it holds today.
- **Google Calendar.** Claude's Google connectors work on your Team plan once you switch them on
  as an owner.
- **Checked on a copy, not yet live:** the event pages were checked inside a copy of your site's
  theme and header, at desktop and phone width; check them once more on the live site.
- **Data retention.** Sign-ups, including the diagnosis, stay in Fluent Forms as they do today.
  `workspace/vault/org/data-retention.md` sets out the choices for you to decide.
- **Your wish list beyond the hackathon:** photo editing, volunteer training,
  donor management and affiliate photographers. Each can be built the same way.

## How it was built

Built in three rounds over three days:
1. A coordinating AI agent and a team of builders wrote the first version in about two hours.
2. Four independent AI reviewers then attacked it: one as you, one as a security tester, one as
   a code reviewer, one as an architect.

They found real problems, all fixed with tests:
- a consent bug that only appears on your FluentCRM version;
- reminders that would have reached nobody;
- permissions a leaked password could abuse.

A fresh reviewer then checked the fixes, and its findings were fixed too:
- reminders still going to cancelled bookings;
- a way to point an event at the wrong page.

A third round added the copy of your site, the saved demo, faster tests, the daily digest, site
checks, nudges, bulk attendance and canvas tracking.

The automated test runs the whole flow on your exact plugin versions and forms: over 220
checks, from a fictional family signing up to their booking, reminders and cancellation, plus a
check that Claude's account is refused on every route that holds family data. The full record is in `docs/build-log.md` and `docs/reviews/`.
