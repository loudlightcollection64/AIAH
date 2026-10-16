# Replica manifest

Captured 2026-10-15 by `site/replica/capture.py` with public GET requests only. Nothing was
copied from family stories, testimonials or photos: see the docstring in capture.py.

| Output | Source | Bytes | SHA-256 (first 12) |
|---|---|---|---|
| home.html | https://somanyangels.org/ | 165088 | cfa5d792106b |
| page-about.html | https://somanyangels.org/about/ | 156277 | e59e4fc64bdc |
| page-contact.html | https://somanyangels.org/contact/ | 160107 | d3e319eed7d7 |
| page-mission-and-impact.html | https://somanyangels.org/mission-and-impact/ | 160435 | c6b0988a3d31 |
| page-donate.html | https://somanyangels.org/donate/ | 157289 | edc0f99198de |
| page-events.html | https://somanyangels.org/events/ | 161601 | 60c1ea751d96 |
| page-likeness-release.html | https://somanyangels.org/likeness-release/ | 184732 | 3c272c700940 |
| page-volunteer.html | https://somanyangels.org/volunteer/ | 173354 | 5f66a6332299 |
| page-select-a-time.html | https://somanyangels.org/select-a-time/ | 193742 | 6dd0451fa47d |
| base-desktop.css | https://somanyangels.org/wp-content/uploads/elementor/css/base-desktop.css | 8064 | 57fbe32cd26f |
| post-4932.css | https://somanyangels.org/wp-content/uploads/elementor/css/post-4932.css | 18926 | 890155aea410 |
| post-64.css | https://somanyangels.org/wp-content/uploads/elementor/css/post-64.css | 10738 | 9f46d7761bac |
| post-67.css | https://somanyangels.org/wp-content/uploads/elementor/css/post-67.css | 3186 | 8b9cec456c04 |
| post-4974.css | https://somanyangels.org/wp-content/uploads/elementor/css/post-4974.css | 6267 | 4981f80425af |
| post-4967.css | https://somanyangels.org/wp-content/uploads/elementor/css/post-4967.css | 2855 | ed020cf8bf51 |
| post-4934.css | https://somanyangels.org/wp-content/uploads/elementor/css/post-4934.css | 5695 | f605eec1a049 |
| post-5066.css | https://somanyangels.org/wp-content/uploads/elementor/css/post-5066.css | 1553 | 7f9ca63e89f3 |
| post-42.css | https://somanyangels.org/wp-content/uploads/elementor/css/post-42.css | 201 | 909064133c6b |
| post-241.css | https://somanyangels.org/wp-content/uploads/elementor/css/post-241.css | 76 | 2fc5e182cc4e |
| post-5160.css | https://somanyangels.org/wp-content/uploads/elementor/css/post-5160.css | 332 | dca88da271ca |
| post-4411.css | https://somanyangels.org/wp-content/uploads/elementor/css/post-4411.css | 357 | 0b1a6417c0d6 |
| post-5187.css | https://somanyangels.org/wp-content/uploads/elementor/css/post-5187.css | 77 | e1896e69632a |
| local-4974-frontend-desktop.css | https://somanyangels.org/wp-content/uploads/elementor/css/local-4974-frontend-desktop.css | 62 | c8b296a81981 |
| local-5160-frontend-desktop.css | https://somanyangels.org/wp-content/uploads/elementor/css/local-5160-frontend-desktop.css | 68 | ef4a835e2b57 |
| logo-150.webp | https://somanyangels.org/wp-content/uploads/2025/05/Sma3ColorLogo-2-150x150-1.webp | 8622 | 14bd919ea963 |

Transformations applied to the page markup: links into the site rewritten to the local
site; the logo to the bundled copy; the home-page photo collage to a drawn placeholder;
YouTube videos to a labelled box; the live forms and booking widget to the local site's
`[fluentform]` and `[fluent_booking_calendar]` shortcodes; the hand-made events list to
`[sma_events]`; the contact form's reCAPTCHA removed; the animated counter frozen at its
final value; `How You Can Help` (404 on the live site) pointed at `/volunteer/`.

Elementor's own stylesheets are not copied: they come from the Elementor plugin folder
that `setup.sh` downloads (same version as the live site).
