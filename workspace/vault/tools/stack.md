---
title: Tool stack
type: note
tags: [tools]
---

# Tool stack

## Website and hosting
- [hosting] IONOS Managed WordPress, plan "IONOS WP Business", contract 91129452, active since 10 Mar 2022, renews 26 Apr 2027
- [hosting] IONOS also provides DNS and the email@somanyangels.org mailbox
- [wordpress] WordPress 7.1.3 on PHP 7.4.33, MariaDB 10.11, theme Solace 2.1.13
- [wordpress] 4 user accounts; supports application passwords for API access
- [wordpress] Exposes an MCP server endpoint and the WordPress Abilities API; FluentCRM and Elementor register their own MCP routes. Whether these are enabled is unknown.

## WordPress plugins in use
| Plugin | Version | Paid | Role |
|---|---|---|---|
| Elementor + Elementor Pro | 4.3.4 / 4.3.1 | Yes | Page building |
| Ultimate Addons for Elementor | 2.9.5 | No | Page building extras |
| Fluent Forms + Pro | 6.2.5 / 6.2.4 | Yes | Sign-up, volunteer and story forms |
| FluentCRM + Pro | 3.1.8 | Yes | Contacts, tags, email automation |
| FluentBooking + Pro | 2.2.0 | Yes | Appointment booking |
| Simply Schedule Appointments | 3.6.12.4 | No | Installed but not used in the family flow; FluentBooking runs /select-a-time/ |
| FluentSMTP | 2.2.95 | No | Sends site email |
| Advanced Custom Fields | 6.8.5 | No | Custom fields |
| Custom Post Type UI | 1.19.2 | No | Custom content types (e.g. family-stories) |
| Air WP Sync | 2.8.0 | Unknown | Pulls Airtable data into WordPress |
| Duplicate Page | 4.5.9 | No | Copies pages |
| Safe Site Logo Fix for Elementor | 1.0 | n/a | Written by Matt with ChatGPT |
| Site Kit by Google | 1.187.0 | No | Analytics, Search Console |
| Limit Login Attempts Reloaded | 3.3.10 | No | Login protection |

## Matt's answers, 14 Oct 2026
- [computers] Two Macs: 2022 M2 laptop (16 GB, macOS Sequoia 15.7.2) and 2021 M1 desktop (16 GB, Sequoia 15.5); can install apps
- [ai] ChatGPT Plus; Claude Team plan with the desktop app installed; also uses Gemini and Grok
- [google] Google for Nonprofits account under matt@somanyangels.org; the tracking spreadsheets live there
- [paid] Pays for Elementor, Fluent Forms, FluentCRM and FluentBooking. Simply Schedule Appointments is not paid, so FluentBooking is the booking tool.
- [mcp] Elementor has an MCP setting; it is off. Nothing reported for FluentCRM.
- [crm] FluentCRM was once set up to send reminders; it sends nothing today
- [staging] Matt doesn't think his IONOS panel offers staging
- [flow] Families sign up at somanyangels.org/likeness, pick an event, then choose a time; no times are open at the moment

## Outside WordPress
- [data] Airtable: family and volunteer information, applications, tracking
- [data] Google Sheets and Docs: registrations, events, volunteers, documents (Google for Nonprofits, matt@somanyangels.org)
- [automation] Zapier: connects forms, spreadsheets and other systems
- [email] Mailchimp: supporter newsletters
- [fundraising] Givebutter: donations, sponsorships, campaigns
- [design] Canva, Adobe Photoshop, Adobe Firefly
- [ai] ChatGPT (primary), Claude (secondary)
- [social] Facebook, Instagram, LinkedIn, X, TikTok, YouTube

## Claude and Google
- [claude] Team plan: an Owner must switch on the Google Workspace connectors for the organisation, then each person connects their own Google account (support.claude.com article 10166901, checked 14 Oct 2026)
- [claude] Calendar actions include viewing, creating, updating and deleting events; Claude asks before each write by default, and Team owners decide whether that can be relaxed
- [google] Google Workspace admin settings may need to trust Claude (third-party guide, unconfirmed)
- [google] Email is on IONOS, so Gmail through Claude is likely empty; Calendar and Drive are the useful parts

## Open questions
- Which system holds the master record for families, volunteers and events: Airtable, Sheets or WordPress?
- Whether the Google for Nonprofits account includes Workspace apps (Calendar, Drive) or only Sheets/Docs; mail itself is on IONOS
- Whether the WP Business plan includes a staging copy (Matt thinks not)

## Relations
- supports [[Event lifecycle]]
- has_issues [[Site health]]

## Sources
- WordPress Site Health report, 13 Oct 2026
- IONOS contract screenshot, 13 Oct 2026
- Matt's ChatGPT summary, 13 Oct 2026, section 3
- Public DNS and REST API index, read 13 Oct 2026
