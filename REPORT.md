# So Many Angels: what we built and how to use it

Matt,

This is a write-up of what our hackathon team built for So Many Angels, how it works, and how to
start using it.

## The short version

You asked for one system to replace the forms, spreadsheets, scheduling tools and emails you
juggle for every event. When we looked at your setup, it turned out you already own nearly
everything that system needs: WordPress, Fluent Forms Pro, FluentCRM Pro, FluentBooking Pro and
Google Workspace. The problem is that none of it talks to each other, so you end up doing the
connecting by hand.

So instead of building something new, we wrote a WordPress plugin that connects the tools you
already pay for. When you publish an event, the site now sets up its sign-up option, booking
times, reminder emails and event page on its own. We also set up your Claude so it understands
So Many Angels and can work on your site safely. Everything runs today on a private copy of your
site with made-up families, and it can go onto your real site whenever you're ready.

## What we noticed about the current setup

Each event is put together by hand: a page built from scratch, the event typed into the sign-up
and volunteer forms, and emails sent one at a time. Your sign-up form and events page are still
showing events that have already happened.

FluentBooking can already send each family reminders before their own session; it just wasn't
hooked into the rest of the process.

We also spotted a few things on the site worth fixing regardless of what you decide about the
rest: error messages are visible to visitors, PHP is on version 7.4 (no security updates since
2022), most plugins are behind on updates, the timezone is set as "-04:00" rather than New York
(so times will be off by an hour after November 1), about 25 GB of hosting space is unaccounted
for, and your account names can be looked up publicly. There's more detail in
`workspace/vault/tools/site-health.md`, and a step-by-step fix list in
`workspace/runbooks/going-live.md`.

## What it does

**Events.** You fill in the city, venue, date, times, number of sessions and how the canvas gets
delivered, either in WordPress (it works on your phone) or by asking Claude to draft it. When you
publish, the site creates the booking times for that day, switches on reminders, adds the event to
your sign-up and volunteer forms, and puts up the event page. Past events disappear on their own.

**Sign-ups.** Families use your existing Fluent sign-up form, unchanged. Each child is a separate
sign-up, siblings included. If the same child signs up twice for the same event, it's flagged as a
possible duplicate. If someone already in your CRM signs up with different details, their record
isn't overwritten; it's flagged so you can check. Every family gets a "we've received your
information" email.

**Approvals.** You approve families from the dashboard. Before anything goes out you see the exact
email, then one click sends them the link to pick a time. Declines are written up as a draft for
you to edit and send yourself. If you decline a family who has already booked, their booking is
cancelled too; the preview tells you that first, and FluentBooking sends them its cancellation
note.

**Booking and reminders.** Approved families pick their own time in FluentBooking. They get a
confirmation, then reminders 14, 7, 3 and 1 days before their session, written the way you write.
Only families who actually booked get reminders. The dashboard shows the dates each family's
reminders will go out.

**Changing an event.** If you take an event back to draft, booking closes and reminders pause for
anyone already booked; their bookings stay put and the dashboard warns you. If you change the date
or time, the families booked at the old time are flagged for follow-up. Nothing gets moved or
cancelled without you knowing.

**After the event.** Tick the families who came and mark them all attended in one go. The dashboard
then offers thank-you emails for families and volunteers, which you preview before sending. Each
family's canvas can be tracked from photos edited, to sent to print, to delivered, and a
Follow-ups list shows every canvas still outstanding.

**Volunteers.** Volunteers apply through your form. You approve them per event, and they get an
email with where to be, when, and what to bring. Portfolio and location questions are ready to add
to your form whenever you want them.

**The dashboard.** One place to see every event, every family's stage (awaiting approval, approved,
booked, attended, needs follow-up, canceled), their photo and story permissions, and your
volunteers. Families who have waited more than a few days for approval are flagged. Everything
exports to a CSV that opens in Google Sheets.

**A daily email.** On mornings when something needs your attention, the site sends you a short
summary: who's waiting for approval, what's coming up in the next three weeks, anything that needs
a look, and new volunteer applications. It only contains counts and volunteer first names, never a
child's details. You can change the time, turn it off, or send yourself a test from Settings.

**Settings and site checks.** One screen covers which forms and calendar to use, session length,
reminder days, how events show on your `/events/` page, and whether editors can see family data
(by default only administrators can). At the bottom is a short checklist of things to fix on the
site, each with a line on how.

The emails are written in your style: warm, short, first names, "Thank you!". The event pages use
your site's colours, fonts and logo.

We also looked at the other routes your brief mentioned (Softr, Replit, Bubble, Lovable, and
using your Fluent plugins on their own). That comparison is in `docs/options.md`.

## Day to day

Without Claude, you'd go to WordPress → Events → Add New, fill in the details and publish, then
approve families on the So Many Angels dashboard.

With Claude, you can just say something like *"New event: Pittsburgh, November 14, 10 to 12, at the
Cranberry studio, 20 sessions."* Claude drafts it, shows you, and publishes when you say yes. It can
also draft announcements, partner emails and posts for you to send.

Either way, the routine emails are sent by your website, not by an AI.

## Keeping families safe

Claude can't see families at all. Its WordPress account can only manage event records: no forms,
contacts, bookings or emails, so even if its password leaked nobody's information would be exposed.
The connector Claude uses enforces the same limit, and there are automated tests for both.

FluentCRM's own built-in AI connection can list, read, delete and email every contact. We don't use
it, and we'd recommend keeping it switched off.

A child's diagnosis never leaves the form entry. It stays out of the CRM, emails, the dashboard,
exports and logs.

Nothing goes to a family without you seeing it first, apart from the automatic "we've received your
information" note, the booking confirmation, and reminders for a session they booked themselves.

The booking page and booking session behind each event can only be set by the website itself, so
nobody editing an event can point it somewhere else.

Everything we built and tested used made-up families.

## Trying it on your Mac

You'll need Claude Code (part of your Claude plan; if it doesn't show up, your seat type may need
changing) and about half an hour the first time.

1. Put the kit's zip in your Downloads folder, open Terminal and paste:
   `cd ~/Downloads && (unzip -oq so-many-angels-kit.zip 2>/dev/null; bash so-many-angels-kit/install.sh)`
   It asks where you want to keep the kit, installs anything your Mac is missing (including Claude
   Code), and opens Claude Code in the kit.
2. Claude runs `/demo`, which starts a private copy of your website with made-up families. The
   first time, loading the sample data takes 15–20 minutes. After that the copy is kept, so next
   time it starts in two or three minutes. Ask Claude for "a reset" to put the sample data back to
   how it started.
3. Open the links it gives you: the events page (what families see), the dashboard (what you see)
   and the mail log (every email the site "sent"). Log in as admin / password.
4. Try asking Claude: *"New event: Columbus, December 12, 10 to 12, Demo Hall, 12 sessions."*

Nothing in the demo touches somanyangels.org, and no real emails go out. The private copy is slower
than your real site, a few seconds per page rather than about one.

## Building more with Claude Code

The kit is set up for you to keep building. Claude reads `CLAUDE.md` in the folder, which explains
how everything fits together, the ground rules (no real family data, never the diagnosis, nothing
changes on the live site until you say so) and how to test. You don't need to read any code:
describe what you want, look at the result on the private copy, and say yes or no.

- `/test` checks the core flow (an event is published, a family signs up, you approve, they book)
  in about 10 minutes. `/test full` runs every check, over 220 of them, in about half an hour.
- `/new-event` sets up an event with Claude.
- `/ship-plugin` walks you through putting the plugin on your real site, backup first.
- `/update-plugins` keeps the private copy on the same plugin versions as your live site.

Every change is saved in the folder's history, so anything can be undone.

## Going live

When you're ready, `workspace/runbooks/going-live.md` has the full steps, and Claude can walk you
through them. The outline:

1. Back up the site in IONOS.
2. Fix the basics: timezone, two-factor login, hide error messages, and check emails actually
   arrive.
3. Install the plugin and fill in So Many Angels → Settings, working through the site checks at
   the bottom of that page.
4. Check your sign-up form's own settings, so its admin email and any CRM feed don't include the
   diagnosis.
5. Create Claude's account, `claude-agent`, with the "SMA Agent" role and nothing else, and connect
   it with `sma-wordpress.mcpb`.
6. Add "Events" to your site menu, since there's currently no link to it.
7. Run one made-up event and sign-up from start to finish, then delete them.

We tested everything on your exact plugin versions (Fluent Forms 6.2.5, FluentCRM 3.1.8,
FluentBooking 2.2.0) and on copies of your real sign-up and volunteer forms, field for field. Your
"Individual Session — Contact Us" and "No specific date" options stay; only the hand-typed dated
events get replaced. One thing to know: if you delete a field from either form and add it back,
its internal name changes, so ask Claude to re-check afterwards.

## Cost

Nothing new. You already have Claude (Team plan) and every plugin we used, and Node.js and Python
are free.

## What's not done yet

- **Airtable.** Air WP Sync is active on your site, but we didn't connect Airtable. Let us know what
  it holds and it can be added.
- **Google Calendar.** Claude's Google connectors work on your Team plan once you switch them on as
  the owner.
- **The live site.** We checked the event pages on a copy of your site's theme, on desktop and
  phone, but they should be checked once more on the real site.
- **Data retention.** Sign-ups, including the diagnosis, stay in Fluent Forms as they do now.
  `workspace/vault/org/data-retention.md` lays out the options for you to choose from.
- **The rest of your wish list:** photo editing, volunteer training, donor management and
  affiliate photographers. Each can be built the same way.

## How it was built

We built this over three days using AI agents. A coordinating agent and a team of builders wrote
the first working version in about two hours. Then four separate AI reviewers went through it
looking for problems, one each from your point of view, a security tester's, a code reviewer's and
an architect's. They found real issues, including a consent bug that only showed up on your
version of FluentCRM, reminders that would never have reached anyone, and permissions that a leaked
password could have abused. All of those were fixed and are now covered by tests. A fresh reviewer
then checked the fixes and caught a couple more, which were fixed too.

A third round added the copy of your site, the faster demo, quicker tests, the daily email, the
site checks, approval nudges, bulk attendance and canvas tracking.

The automated tests run the whole flow on your exact plugin versions and forms, from a made-up
family signing up through to their booking, reminders and cancellation, and confirm that Claude's
account is turned away from everything that holds family data. The full record is in
`docs/build-log.md` and `docs/reviews/`.
