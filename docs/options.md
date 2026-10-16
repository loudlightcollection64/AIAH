# Options: what else could have done the job

For Matt Meiers, October 2026. Your brief (page 3) asked us to look at Softr, Replit Agent, Bubble
and Lovable before writing anything from scratch, and to compare Softr with a custom AI-built app.
We didn't do that on day one. This comparison was written afterwards, and adds one more option:
the Fluent plugins you already own, with no custom code.

Prices come from each vendor's pricing page, read 14 Oct 2026, or are marked "estimate". Check
before you sign up.

## At a glance

| | New cost | Upkeep after we leave | Children's data | Fits WordPress | Phone | Speed to working flow | Other cities |
|---|---|---|---|---|---|---|---|
| Softr + Airtable | ~$50/mo | Low skill | New vendor | Separate app | Good | Fast | Good |
| Replit Agent | ~$20/mo + hosting | Needs a coder | New vendor | Separate app | Depends | Medium | Flexible |
| Bubble | $59/mo+ | Bubble skills | New vendor | Separate app | Good | Medium | Good |
| Lovable | ~$25/mo (est.) | Needs a coder | Two new vendors | Separate app | Depends | Fast to demo | Flexible |
| Fluent, no code | $0 | Low skill | Stays on your site | Native | Clunky | Medium | Weak |
| **What we built** | $0 | Needs a steward | Stays on your site | Native | Basic | Done (local) | Weak |

"New cost" is only money on top of what you pay today: IONOS hosting, Elementor Pro, Fluent
Forms/CRM/Booking Pro, Google for Nonprofits, Airtable and Zapier.

## Each option in a paragraph

**Softr (with Airtable or Google Sheets).** Softr builds portals, forms and dashboards on top of a
spreadsheet-style database. Its list prices are Basic $19, Pro $99 and Business $329 a month, with a
50% discount for nonprofits (softr.io/pricing, 14 Oct 2026; the page has a monthly/yearly switch
and we couldn't confirm which one these figures are). You'd need Pro, so about $50 a month after
the discount: Basic has no fine-grained permissions, and Pro adds "granular permissions &
visibility" and Google sign-in. An installable phone app is on Business only. If Airtable
becomes the main record and you add seats, Airtable Team is $20 per user a month, billed yearly
(airtable.com/pricing, 14 Oct 2026). Your current Airtable plan is unknown to us.
Softr is the fastest route to a working dashboard, and you could maintain it yourself. The cost:
family records move to two more companies, your website becomes a signpost to a separate app,
and reminders and booking get rebuilt in Softr workflows or Zapier while your Fluent licences
sit idle.

**Replit Agent.** You describe the app in English, and Replit writes and hosts the code. Core is
$20 a month, or $18 a month billed yearly (replit.com/pricing, 14 Oct 2026). That page doesn't
show hosting charges, so the running cost of a live app is an estimate: likely more than $20 a
month. It's flexible, but you'd own a codebase, like ours, on a new host. When it breaks, you
need someone who reads code.

**Bubble.** An AI-generated app that you then keep editing visually, without code. The Starter
plan is $59 a month billed yearly. The free plan is for building only, not for running a live
app (bubble.io/pricing, 14 Oct 2026). Bubble offers a nonprofit discount, but the page doesn't
say how much. It's good on phones and for many cities with separate logins. As your brief says,
you're tied to Bubble: if prices rise, you pay or rebuild.

**Lovable.** Chat-built web apps, usually with a Supabase database behind them. The official
pricing page didn't show plan prices when we read it. Third-party guides from 2026 put Pro at
about $25 a month (estimate, Oct 2026). One credit balance pays for building, hosting and AI
features, and extra credits on Pro cost $15 per 50 (docs.lovable.dev, 14 Oct 2026). It's the
quickest to an impressive demo. Making that demo safe for children's records still needs a
developer to check the database permissions.

**Configure Fluent natively, no code.** You already own the engines. Fluent Forms Pro sends each
sign-up to FluentCRM and tags it. Approving a family is a tag change in FluentCRM, which starts an
automation that emails the booking link. FluentBooking Pro sends reminders before each family's
own booking. CPT UI, ACF and Elementor give you an event page template. We estimate this covers
about 70% of your wish list. What's missing: an event dropdown that updates itself, setting up
an event in one step, a combined dashboard, and keeping the diagnosis out of exports. Those
become checklist steps. It costs nothing new, survives plugin updates best, and anyone who knows
WordPress can help. It's the fallback if our plugin is ever abandoned.

**What we built.** One WordPress plugin on your Fluent tools. Publishing an event creates its
page, sign-up option, CRM tag and FluentBooking session with reminders. A dashboard tracks each
family's stage. A fenced Claude connector handles events and pages but can't see families. No
new subscriptions; data stays on your site; the diagnosis stays out of the CRM, emails, dashboard
and export. It works in your phone's browser, but WordPress admin isn't built for phones.

## Children's data, for every option

HIPAA doesn't apply to a charity like yours. What does apply: your own privacy policy (what you
collect, why, how long you keep it) and the terms of every company that stores the data. Each
hosted option adds at least one company. Read its terms and data processing agreement before
real families go in. Whatever you
choose, collect less. "Eligibility confirmed" plus "accommodations needed" protects children
better than a free-text diagnosis field. As your brief says, no platform, ours included, should
hold real children's information until its permissions have been tested on your live setup.

## Our conclusion, honestly

We chose WordPress and your Fluent tools: you already pay for them, your site stays the public
front, families' data stays where it lives, and nothing new is billed. For you, today, that was
right.

It isn't free. Our plugin is several thousand lines of custom code on a site with PHP 7.4 and no
staging copy. Any Fluent update could break it, and nobody would notice until a family missed an
email. Our own architecture review says so. Before it goes live, it
needs a named person, ideally a local volunteer developer, for about an hour a month (estimate),
and a test on your updated plugins. If that person never appears, switch back to the no-code
Fluent setup above. It does most of the job with nothing to maintain.

Softr would have been the better choice if you had no WordPress site or no Fluent licences. It
would also win if Airtable were to be the main record for families and events, or if affiliates
in other cities soon need logins that show only their own families, which our plugin can't do
yet. If national expansion becomes the priority, look at Softr on Airtable again, with a
permissions test first.
