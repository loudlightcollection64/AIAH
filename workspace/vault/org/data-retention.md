---
title: Data retention
type: note
tags: [privacy, policy]
status: PROPOSAL for Matt to decide (16 Oct 2026); nothing is deleted automatically
---

# Data retention (proposal)

So Many Angels holds children's medical information (the diagnosis on the sign-up form). Today
it stays in the Fluent Forms entry indefinitely. This note sets out choices for Matt; it becomes
policy only when he picks one and changes the status line above.

## What is held, and where
- **Sign-up form entry** (Fluent Forms): child's name, age, **diagnosis**, costume, guardian
  contact details, address, consents, signature. The only place the diagnosis is stored.
- **CRM contact** (FluentCRM): guardian name, email, phone, event tags and stages, booking times,
  decisions. No diagnosis.
- **Booking** (FluentBooking): guardian name, email, time.
- **Photos** (Drive / editing tools): the child's image. Use governed by the consents.

## Choices for the diagnosis
1. **Delete the diagnosis after the canvas is delivered** (recommended): blank that one field in
   the form entry once the family's canvas is marked delivered; keep the rest of the entry for
   the record and consents.
2. **Delete the whole form entry 12 months after the session,** keeping the CRM contact.
3. **Keep everything** (today's behaviour). Simplest, but the most to protect.

## Choices for everything else
- CRM contacts: keep while the family wants news (they can unsubscribe); delete on request.
- Consents and signature: keep as long as any photo of the child is in use.
- Requests to delete: honoured within 30 days; Claude can walk Matt through it.

## Who can see what
- Administrators only, by default (Settings → "Editors may see family data" is off).
- Claude's account can't see families at all.
- Review the user list monthly (`runbooks/monthly-maintenance.md`).

## Relations
- protects [[Event lifecycle]]
- used_by [[Monthly maintenance]]
