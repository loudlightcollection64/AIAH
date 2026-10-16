# So Many Angels brand

What the live site at somanyangels.org actually uses, read from its public CSS on 14 Oct 2026,
and how the plugin's design tokens in `plugin/sma-events/assets/sma.css` follow it. Where the
plugin departs from the live site, the reason is stated (almost always contrast).

## Palette

### Elementor global colours (kit `post-4932.css`, class `.elementor-kit-4932`)

| Variable | Hex | Used for on the live site |
|---|---|---|
| `--e-global-color-primary` | `#F5A706` | Amber. Button background (kit `button`, `.elementor-button`), footer bands (`post-67.css`), header accents |
| `--e-global-color-secondary` | `#9D5A18` | Brown. `h3` colour in the kit; header accents (`post-64.css`) |
| `--e-global-color-text` | `#000000` | Body text, links, `h1`, `h2`, `h4` |
| `--e-global-color-accent` | `#FFFFFF` | White |
| `--e-global-color-7d03fda` | `#6B6266` | Warm grey. `h5` colour |
| `--e-global-color-307a77b` | `#D3CACC` | Silver (matches the logo's wings). `h6` colour |

### Solace theme palette (inline `solace-*-inline-css` on every page, mirrored as `--e-global-color-solcolor*`)

| Variable | Hex | Used for |
|---|---|---|
| `--sol-color-base-font` / `solcolorbasefont` | `#000000` | Body text |
| `--sol-color-heading` / `solcolorheading` | `#271D16` | Headings (very dark brown) |
| `--sol-color-link-button-initial` / `solcolorlinkbuttoninitial` | `#F2751A` | Link and theme-button orange |
| `--sol-color-link-button-hover` / `solcolorlinkbuttonhover` | `#B5510A` | Link hover (burnt orange) |
| `--sol-color-button-initial` / `solcolorbuttoninitial` | `#F5A706` | Button background (= primary) |
| `--sol-color-button-hover` / `solcolorbuttonhover` | `#B5510A` | Button hover background (kit `button:hover`) |
| `--sol-color-selection-initial` / `solcolorselectioninitial` | `#211F17` | Text selection |
| `--sol-color-selection-high` / `solcolorselectionhigh` | `#FFAE00` | Selection highlight |
| `--sol-color-border` / `solcolorborder` | `#EAE3DF` | Borders, dividers (home page uses it as a band background) |
| `--sol-color-background` / `solcolorbackground` | `#FAF9F8` | Page background (warm off-white) |
| `--sol-color-page-title-text` / `solcolorpagetitletext` | `#FDFBF9` | Page-title text |
| `--sol-color-page-title-background` / `solcolorpagetitlebackground` | `#9D5A18` | Page-title band (brown) |
| `--sol-color-bg-menu-dropdown` / `solcolorbgmenudropdown` | `#FFFFFF` | Menu dropdowns |

Other colours seen: `#F5A506` (footer band, a near-duplicate of primary), header shadows
`0 0 24px rgba(0,0,0,.15)` and `0 0 27px rgba(0,0,0,.12)`, and the Fluent Forms submit button,
which the live pages leave at Fluent's default blue `#1A7EFB` (`post-5160.css`, `post-4411.css`,
`!important`). White on that blue is 3.87:1, which fails AA for 16px text; the plugin re-skins the
button in brand colours instead.

### Plugin additions (not on the live site)

Status colours for badges and form messages, chosen as tints that sit with the warm palette:
amber tint `#FFF4DB`, awaiting text `#7A4A0F`, green `#1E6B34` on `#E6F4EA`, blue `#1D4F86` on
`#E5F0FB`, brown `#6E3F11` on `#F3E9DF`, grey `#4D4649` on `#EFEDEE`, red `#A12A1C` on `#FDE8E6`.
Form-control border `#8A8084` (the theme's `#EAE3DF` is too faint to mark a field boundary).

## Contrast (WCAG 2.x, computed)

Ratios are computed with the WCAG relative-luminance formula, not estimated. AA needs 4.5:1 for
normal text, 3:1 for large text (24px, or 18.66px bold) and for UI boundaries such as input borders
and focus rings.

### Recommended pairs (used in `sma.css`)

| Foreground | Background | Ratio | Use | Passes |
|---|---|---|---|---|
| `#000000` text | `#FFFFFF` card | 21.00 | Body text on cards | AA, AAA |
| `#000000` text | `#FAF9F8` page | 19.97 | Body text on page | AA, AAA |
| `#271D16` heading | `#FFFFFF` | 16.49 | Headings, focus ring on white | AA, AAA |
| `#271D16` heading | `#FAF9F8` | 15.68 | Headings, focus ring on page | AA, AAA |
| `#271D16` ink | `#F5A706` amber | 8.18 | **Primary button** text | AA, AAA |
| `#FFFFFF` | `#B5510A` burnt | 5.06 | Primary button hover | AA |
| `#9D5A18` brown | `#FFFFFF` | 5.37 | Secondary button text, eyebrows, `dt` labels | AA |
| `#9D5A18` brown | `#FAF9F8` | 5.11 | Same, on page background | AA |
| `#FFFFFF` | `#9D5A18` brown | 5.37 | Secondary button hover | AA |
| `#FDFBF9` | `#9D5A18` brown | 5.20 | Page-title band (if a template uses it) | AA |
| `#B5510A` burnt | `#FFFFFF` | 5.06 | Links | AA |
| `#B5510A` burnt | `#FAF9F8` | 4.82 | Links on page background | AA |
| `#6B6266` muted | `#FFFFFF` | 5.89 | Help text, table labels | AA |
| `#6B6266` muted | `#FAF9F8` | 5.60 | Muted text on page | AA |
| `#8A8084` control border | `#FFFFFF` | 3.82 | Input borders (UI, 3:1) | AA (non-text) |
| `#8A8084` control border | `#FAF9F8` | 3.63 | Input borders on page (UI, 3:1) | AA (non-text) |
| `#A12A1C` error | `#FFFFFF` | 7.33 | Form errors, required marker | AA, AAA |
| `#A12A1C` error | `#FAF9F8` | 6.97 | Same, on page | AA |
| `#000000` text | `#E6F4EA` | 18.49 | Fluent success message body | AA, AAA |
| `#7A4A0F` | `#FFF4DB` | 6.83 | Badge: awaiting-approval, volunteer-applied | AA |
| `#1E6B34` | `#E6F4EA` | 5.76 | Badge: approved, volunteer-approved | AA |
| `#1D4F86` | `#E5F0FB` | 7.23 | Badge: booked | AA, AAA |
| `#6E3F11` | `#F3E9DF` | 7.36 | Badge: attended | AA, AAA |
| `#4D4649` | `#EFEDEE` | 7.88 | Badge: canceled, volunteer-declined | AA, AAA |
| `#A12A1C` | `#FDE8E6` | 6.24 | Badge: needs-follow-up | AA |

### Pairs to avoid (live site uses some of them)

| Foreground | Background | Ratio | Note |
|---|---|---|---|
| `#FFFFFF` | `#F5A706` | 2.02 | The live site's button (white on amber). Fails AA. The plugin uses dark ink on amber instead |
| `#F2751A` | `#FFFFFF` | 2.86 | The theme's link orange. Fails for text; the plugin uses `#B5510A` for links |
| `#FFFFFF` | `#1A7EFB` | 3.87 | Fluent Forms default submit button. Fails for 16px text |
| `#F5A706` | `#FFFFFF` | 2.02 | Amber as text or as a focus ring on white. Decorative only |
| `#D3CACC` | `#FFFFFF` | 1.60 | Silver. Decorative only (dividers, illustration) |
| `#EAE3DF` | `#FFFFFF` | 1.27 | Theme border. Fine for card edges (cards also have a shadow), not for inputs |
| `#B06E0C` | `#FFFFFF` | 4.13 | The logo's lettering colour. Not for body text |

## Type

Fonts the live site loads (do not load them again from the plugin):

- **DM Sans** (body, buttons, labels): `https://fonts.googleapis.com/css?family=DM+Sans%3A400%2C500%2C600&display=swap` (Solace), and Elementor's local copy `wp-content/uploads/elementor/google-fonts/css/dmsans.css`
- **Outfit** (headings): `https://fonts.googleapis.com/css?family=Outfit&display=swap`, and `wp-content/uploads/elementor/google-fonts/css/outfit.css`

`sma.css` declares both with system fallbacks (`system-ui, -apple-system, "Segoe UI", Roboto, …`)
so the plugin renders cleanly offline and on the local site.

Scale, from the kit's Solace typography variables (`--e-global-typography-solace_*`):

| Style | Family | Weight | Desktop (>1024px) | Tablet (768–1024) | Mobile (≤767) | Line height | Token |
|---|---|---|---|---|---|---|---|
| H1 | Outfit | 500 | 68px | 50px | 38px | 1.0 | `--sma-fs-h1` |
| H2 | Outfit | 500 | 50px | 38px | 28px | 1.1 | `--sma-fs-h2` |
| H3 | Outfit | 500 | 38px | 28px | 21px | 1.2 | `--sma-fs-h3` |
| H4 | Outfit | 500 | 28px | 21px | 18px | 1.3 | `--sma-fs-h4` |
| H5 | Outfit | 500 | 21px | 18px | 16px | 1.3 | (not tokenised) |
| H6 | Outfit | 500 | 16px | 14px | 14px | 1.3 | (not tokenised) |
| Body | DM Sans | 500 | 16px | 16px | 16px | 1.5 | `--sma-fs-body` |
| Small / smaller | DM Sans | 500 | 13px | 12px | 12px | 1.5 | `--sma-fs-small` (13px everywhere; 12px is too small to read comfortably) |
| Label / logotitle (eyebrow) | DM Sans | 500, uppercase, 1.5px tracking | 13px | 16px | 16px | 1.5 | `--sma-fs-label`, `--sma-ls-label` |
| Accent | DM Sans | 600 | 33px | 16px | 16px | 1.5 | (not used) |

Note: the kit's element rules point `h1`–`h4` and `h6` at the 13px "primary" style, while the page
widgets set their own sizes (the home page uses 31px headings, the KC event page 43px / 35px). The
Solace scale above is the only consistent scale in the CSS, so the plugin uses it.

## Buttons

Live site: pill shape (`border-radius: 100px`), DM Sans, amber `#F5A706` background with white
text, hover `#B5510A`. Theme buttons pad `13px 15px`.

Plugin (`.sma-btn`):

- **Primary** (`.sma-btn--primary`): pill, amber `#F5A706`, **ink text `#271D16`** (8.18:1) instead
  of white (2.02:1); hover burnt `#B5510A` with white text (5.06:1). DM Sans 600, 16px, padding
  12px 24px, minimum height 44px.
- **Secondary** (`.sma-btn--secondary`): pill, transparent, 2px brown `#9D5A18` border and text
  (5.37:1); hover fills brown with white text.
- **Focus**: 3px solid `#271D16` outline, 2px offset, on `:focus-visible`.
- The Fluent Forms submit button inside `.sma` gets the primary style.

## Cards

The live site has no card component; its boxes use the header's soft shadow and the theme border.
Plugin `.sma-card`: white surface, 1px `#EAE3DF` border, 12px radius, shadow
`0 0 24px rgba(0,0,0,.08)` deepening on hover, 24px padding (the kit's widget spacing), optional
4:3 image (`.sma-card__media`), actions pinned to the bottom. Cards sit in `.sma-grid`, which fills
columns of at least 18rem.

## Logo

- File: `wp-content/uploads/2025/05/Sma3ColorLogo-2-150x150-1.webp`, 150×150, circular badge:
  "SO MANY ANGELS" lettering and "SoManyAngels.org" in gold-brown (`~#B06E0C`), silver wings
  (`~#C2B7B4`), an amber diamond shield with an outlined "A" and a crown above, "TM" mark.
- Header shows it at 120px wide on desktop and 70px on mobile (`post-64.css`).
- Use it on white or `#FAF9F8` only; the silver wings disappear on darker or busy backgrounds.
- Keep it square, at least 70px wide, never recoloured or cropped.
- The live site's logo `<img>` has an empty `alt`. Plugin templates should use `alt="So Many Angels"`
  when the logo is the only home link.

## Tone

From `workspace/vault/org/voice.md` (how Matt writes); applies to page copy as well as emails:

- Warm, personal, conversational, concise and direct.
- No corporate or over-polished wording, no exaggerated praise, no inspirational clichés.
- Families: especially warm and easy to understand. Say what to do, when, and who to contact.
  Never assume every child will recover.
- Volunteers: appreciative but practical: where to be, when, what to bring, what they're
  responsible for.
- Never promise what can't be delivered; never invent details about children, families or sponsors.
- The plain promise from the example confirmation fits button and heading copy: "have some fun,
  take some great photos, and let your kid be a kid".

Visual tone follows: warm off-white page, generous spacing, rounded friendly shapes, amber as the
one strong accent. Status badges always carry a text label, never colour alone.

## Sources

Fetched with read-only GETs on 14 Oct 2026:

- https://somanyangels.org/ (home page HTML, inline Solace styles)
- https://somanyangels.org/kc/ (KC June 2026 event page)
- https://somanyangels.org/likeness/ (redirects to https://somanyangels.org/likeness-release/)
- https://somanyangels.org/volunteer/
- https://somanyangels.org/wp-content/uploads/elementor/css/post-4932.css (Elementor kit: global colours and typography)
- https://somanyangels.org/wp-content/uploads/elementor/css/post-4974.css (home page)
- https://somanyangels.org/wp-content/uploads/elementor/css/post-64.css (header)
- https://somanyangels.org/wp-content/uploads/elementor/css/post-67.css (footer)
- https://somanyangels.org/wp-content/uploads/elementor/css/post-5817.css (KC event page)
- https://somanyangels.org/wp-content/uploads/elementor/css/post-5160.css (likeness release page)
- https://somanyangels.org/wp-content/uploads/elementor/css/post-4411.css (volunteer page)
- https://somanyangels.org/wp-content/themes/solace/style-main-new.min.css?ver=2.1.13 (Solace theme stylesheet)
- https://somanyangels.org/wp-content/uploads/2025/05/Sma3ColorLogo-2-150x150-1.webp (logo, for colours)

Google Fonts links were read from the page HTML, not fetched. Fluent Forms class names and its
`--fluentform-*` variables were read from the local plugin copy in `site/plugins-cache/fluentform/`
(`app/Services/FormBuilder/Components/BaseComponent.php`, `SubmitButton.php`,
`assets/css/fluent-forms-public.css`, `assets/css/fluentform-public-default.css`).
