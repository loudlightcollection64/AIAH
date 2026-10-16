---
title: New event runbook
type: runbook
tags: [runbook, events]
status: round 2 (site sets up booking on publish), 14 Oct 2026
---

# New event

Use when Matt says something like: "New event: Pittsburgh, November 14, 10 to 12, at the
Cranberry studio, 20 sessions."

**How it works:** Claude writes the event's details. When Matt publishes it, **the website sets
up everything else itself**:
- booking sessions on that day, about 10 minutes each, capped at the number of sessions;
- reminder emails at 14, 7, 3 and 1 days before each family's own session;
- the booking confirmation;
- the event's CRM tag;
- the event page, and the event's place in the sign-up and volunteer dropdowns.

Claude doesn't schedule or send anything.

Every change is done twice: first without `apply` to get a preview, which you show Matt, then
with `apply: true` only after he says yes.

## 1. Gather
Confirm, in one message, anything missing:
- city, venue name and full address;
- date, start and end time, and the venue's timezone (Central for Kansas City, for example);
- number of photo sessions;
- how the canvas reaches families: shipped, or presented at a reveal event (where and when).

Run `check_connection`. If it reports a problem, tell Matt in one sentence and stop.

## 2. Draft
`save_event` with the details, `publish: false`. Show Matt the preview; on yes, save it.
Tell him he can also look at or change the draft in WordPress → Events.

## 3. Publish (opens booking)
When Matt says it can go live: `save_event` with the event's `id` and `publish: true`.
- The preview explains that publishing opens booking. Show it.
- On yes, apply.
- After about a minute, run `get_event`:
  - **"Ready"** means families can sign up and book. Give Matt the event page link (`link`).
  - **"Problem: …"** means the site couldn't set something up. Read him the message, and
    suggest he opens the event in WordPress, where the same message appears.

## 4. Spread the word (optional)
Offer drafts in Matt's voice (`vault/org/voice.md`): a short announcement email for partners, a
social post, a note for HopeKids or the venue. Drafts only, for him to send. Never mention a
child's diagnosis.

## Afterwards
Approvals, declines, bookings and thank-yous happen on the So Many Angels dashboard in
WordPress, where each email is previewed before it goes. Claude can't see families; if Matt
asks about a family, point him to the dashboard.

## Never
- Try to reach families, forms, bookings or emails another way. The connector refuses, and so
  does Claude's WordPress account.
- Publish without Matt's yes.

## Relations
- implements [[Event lifecycle]]
- follows [[Matt's voice]]
