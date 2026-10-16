# Design system

- `brand.md`: what the live site uses (palette, type, buttons, logo, tone), with computed contrast
  ratios and the URLs it was read from.
- `../../plugin/sma-events/assets/sma.css`: the plugin's only stylesheet for visual decisions.

## Using it

Wrap every piece of plugin output (public templates and admin views) in an element with class
`sma`; add `sma-page` on a full-page wrapper to get the page background and vertical padding. The
tokens are defined on `.sma`, not `:root`, so nothing outside the wrapper is affected and the
Solace theme's variables can't leak in. Enqueue `sma.css` after the theme stylesheet.

Components: `.sma-btn` + `--primary` / `--secondary` / `--block`, `.sma-card` (`__media`,
`__body`, `__title`, `__actions`), `.sma-grid`, `.sma-event-meta` (a `<dl>`), `.sma-badge` +
`--<stage slug>` (the six family stages and three volunteer stages from build-spec section 5),
`.sma-table` (every `<td>` needs `data-label="Column name"` for the stacked mobile layout),
`.sma-eyebrow`, `.sma-muted`, `.sma-container`, `.sma-stack`, `.sma-visually-hidden`,
`.sma-visually-hidden-focusable`. A Fluent Forms form placed inside `.sma` is re-skinned
automatically, including Fluent's own `--fluentform-*` variables.

## How tokens map to brand.md

| Token group | brand.md section |
|---|---|
| `--sma-color-*` (raw palette, comments name the live `--e-global-color-*` variable) | Palette |
| `--sma-bg`, `--sma-fg*`, `--sma-link*`, `--sma-border*`, `--sma-focus`, `--sma-error*`, `--sma-success*` (roles) | Contrast, recommended pairs |
| `--sma-btn-*` | Buttons |
| `--sma-badge-*` | Contrast, badge rows |
| `--sma-font-*`, `--sma-fs-*`, `--sma-fw-*`, `--sma-lh-*`, `--sma-ls-*` | Type |
| `--sma-space-*`, `--sma-radius-*`, `--sma-shadow-*`, `--sma-container` | Cards, Buttons |
| `--sma-focus-ring`, `--sma-tap-min`, `--sma-transition` | Buttons (focus), accessibility |

## Swapping in new visuals (e.g. Claude Design output)

1. Replace the values in the first `.sma { … }` block of `sma.css` (and the two `@media` blocks
   that widen the heading sizes). Keep the token names; components read only tokens.
2. Change role tokens (`--sma-fg`, `--sma-btn-primary-bg`, …) rather than component rules where
   possible. If the new design needs a structural change, edit the component rule, not templates.
3. Re-check every text/background pair against 4.5:1 (3:1 for borders and focus rings) and update
   the contrast tables in `brand.md`.
4. Load a page with plugin output and check the browser console for CSS errors.

Known limit: a Fluent Forms form with inline style attributes set in its own settings can still
override colours that `sma.css` sets by class; leave the form's button style on the default.
