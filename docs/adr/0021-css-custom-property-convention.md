# ADR-0021: Theme-overridable CSS custom-property convention

- **Status:** Accepted
- **Date:** 2026-09-02
- **Component/Subsystem:** `css/webform_ranking.matrix.css`, `css/webform_ranking.dragdrop.css`

## Context & Problem Statement

Both display styles hardcoded spacing/border values directly in CSS,
with no way for a site's theme to adjust them without an `!important`
override fight. `matrix.css` had already informally started chaining
two values through Olivero core theme's own spacing scale during #115
(`var(--sp1, 1.125rem)`, `var(--sp0-5, ...)`) — a reasonable instinct,
but ad hoc, undocumented, and Olivero-only. GitHub issue #116 asks for
this to be formalized across both files, aligned with "custom properties
used in standard Drupal themes," and — explicitly — genuinely
overridable by a child theme, not just theoretically.

Surveying the three themes actually named (Olivero, Claro, Gin) found
real, usable conventions:

- **Olivero** (core default frontend theme): `--sp0-5`/`--sp1`/... (a
  1.125rem-based spacing scale), `--color--gray-*`, `--color--primary-*`,
  `--border-radius: 0.1875rem`.
- **Claro** (core default admin theme): un-prefixed, generic names —
  `--space-xs`/`-s`/`-m`/..., `--input-border-color`,
  `--input-border-size`, `--input-padding-vertical`/`-horizontal`,
  `--color-focus`, `--base-border-radius`, `--input-border-radius-size`.
- **Gin** (contrib admin theme): `base theme: claro` in `gin.info.yml`
  — a genuine Claro subtheme. Gin re-skins most things under its own
  `--gin-*` prefix but deliberately leaves Claro's plain `--space-*`/
  `--color-focus` untouched, and directly reuses Claro's exact
  unprefixed names for `--input-line-height`/`--input-padding-*`. So
  Claro's unprefixed tokens are a real de-facto standard, live on Gin
  sites too, not just plain-Claro ones.

**The pitfall that shaped the decision below:** a first draft of this
work declared the module's own tokens with static values directly on
each component's root class, e.g. `.webform-ranking-dragdrop { --webform-ranking-border-color: #ccc; }`.
For an inherited property (custom properties are inherited by default,
same as `color`), a value *directly specified* on an element always
wins over one the element only *inherits* from an ancestor — this isn't
a specificity contest, inheritance simply yields to any specified value
on the element itself. That means a theme's own, naturally-written
`:root { --webform-ranking-border-color: green; }` override would
silently never reach `.webform-ranking-dragdrop` at all — only a theme
rule targeting `.webform-ranking-dragdrop` specifically would win. That
is the opposite of "easily extendable by a child theme," and is exactly
the kind of mistake that looks correct until someone actually tries to
override it the normal way.

## Decision

`--webform-ranking-*` custom properties are **never given a specified
value anywhere in this module's own CSS.** They are referenced only
inline, at each point of use, as `var(--webform-ranking-<name>,
<fallback-chain>)`. Left genuinely unset by this module, the property
is free to inherit uncontested from whatever a child theme sets — at
`:root` (the normal, expected place) or a more targeted selector — and
our own fallback chain only activates when nothing upstream defines it
at all.

Each fallback chain nests through the real Claro/Gin-shared name (tried
first, since it's the more generic/portable convention), then the real
Olivero-specific name, then a static literal:

| Token | Fallback chain |
|---|---|
| `--webform-ranking-border-color` | `var(--input-border-color, var(--color--gray-70, #ccc))` |
| `--webform-ranking-border-style` | `solid` (no cross-theme convention exists for this) |
| `--webform-ranking-border-width` | `var(--input-border-size, 1px)` |
| `--webform-ranking-border-radius` | `var(--input-border-radius-size, var(--base-border-radius, var(--border-radius, 0)))` (chained since #120 — see below for the reversal from this token's original, deliberately-unchained state) |
| `--webform-ranking-focus-color` | `var(--color-focus, var(--color--primary-50, #0f62c9))` |
| `--webform-ranking-item-gap` | `var(--space-xs, var(--sp0-5, 0.5em))` |
| `--webform-ranking-item-padding-block` | `var(--input-padding-vertical, 0.5em)` |
| `--webform-ranking-item-padding-inline` | `var(--input-padding-horizontal, 0.75em)` |
| `--webform-ranking-rank-label-spacing` | `var(--sp0-5, calc(0.5 * 1.125rem))` (unchanged from #115) |
| `--webform-ranking-narrow-margin` | `var(--sp1, var(--space-m, 1.125rem))` |
| `--webform-ranking-control-size` | `var(--input-line-height, 2rem)` (added in #118 — the drag/drop move buttons' and N/A checkbox's shared `box-sizing: border-box` width/height, applied identically to both via one selector, paired with `font: inherit` so native form controls' own em-basis can't drift from the page's type scale; `2rem` clears WCAG 2.2's 24px target-size minimum (SC 2.5.8); no Olivero equivalent exists for "form control height," so that layer is skipped for this token) |
| `--webform-ranking-button-bg` | `var(--button-bg-color, transparent)` (added in #118 — move-button background; Claro's real button token, Gin inherits it unchanged; no Olivero equivalent, so `transparent` rather than an invented brand color) |
| `--webform-ranking-button-fg` | `var(--button-fg-color, currentColor)` (move-button text/icon color — the icon's own `fill="currentColor"` follows this automatically, no separate icon token needed) |
| `--webform-ranking-button-hover-bg` | `var(--button--hover-bg-color, transparent)` |
| `--webform-ranking-button-active-bg` | `var(--button--active-bg-color, transparent)` |
| `--webform-ranking-button-disabled-bg` | `var(--button--disabled-bg-color, transparent)` (the topmost/bottommost item's move button; paired with a static `opacity: 0.5` fallback affordance, matching `.webform-ranking-matrix__radio--taken`'s own convention, since disabling a button via class selector alone would otherwise override the browser's native `:disabled` dimming) |
| `--webform-ranking-button-disabled-fg` | `var(--button--disabled-fg-color, currentColor)` |
| `--webform-ranking-button-focus-color` | `var(--button--focus-border-color, var(--webform-ranking-focus-color, var(--color-focus, var(--color--primary-50, #0f62c9))))` (the move buttons' `:focus-visible` outline — tries Claro's button-specific focus token before falling back through the generic `--webform-ranking-focus-color` chain `.webform-ranking-dragdrop__item:focus-visible` already uses) |
| `--webform-ranking-button-hover-fg` | `var(--webform-ranking-button-fg, var(--button-fg-color, currentColor))` (Claro's generic button has no distinct hover foreground — only its primary/CTA variant does — so this intentionally resolves back to the base button foreground; still a real override point for a theme that does distinguish it) |
| `--webform-ranking-button-active-fg` | Same fallback shape as `-hover-fg`, for `:active` |
| `--webform-ranking-button-focus-bg` | `var(--webform-ranking-button-bg, var(--button-bg-color, transparent))` (same reasoning as `-hover-fg` above, applied to the focus state's background) |
| `--webform-ranking-button-focus-fg` | Same fallback shape as `-hover-fg`, for `:focus-visible` |
| `--webform-ranking-na-opacity` | `var(--input--disabled-border-opacity, 0.7)` (added in #120 — `.webform-ranking-dragdrop__item--na`'s opacity; `0.7` is the original literal value, preserved as the static default so behavior is unchanged absent a matching theme token; Claro/Gin's disabled-state opacity is the closest real semantic match found, no Olivero equivalent exists) |

**Updated in #120:** `--webform-ranking-border-radius` originally
shipped in #116/#118 as a **deliberate exception** to "chain through a
real theme token" — a literal `0`, explicitly not chained through
Olivero's `--border-radius` or Claro's `--input-border-radius-size`,
reasoning that chaining it would silently round every corner on, e.g.,
every Olivero site the moment it shipped, ahead of an admin-
configurable toggle #120 was expected to add to make that an
intentional choice rather than a side effect. #120 itself later
reconsidered: an admin toggle doesn't serve "blend into the site's
theme" any better than the theme's own tokens already do, and adds a
config surface for what's really a styling concern — so no toggle was
added, and the original blocking rationale no longer applies. The
token is now chained like every other one in this table. Because the
property was never *specified* by module CSS even in its unchained
state, this was already a non-breaking change for any theme/site that
had started relying on the override contract early — nothing that
worked before stops working now.

## How a theme overrides these

Either of the following works, since nothing in module CSS pre-empts
inheritance:

```css
/* Broadest — affects every instance of this element on the site. */
:root {
  --webform-ranking-border-color: #0a5;
}

/* Targeted — only this component. Wins over a :root declaration of the
   same name, by normal specified-value-beats-inherited-value rules. */
.webform-ranking-dragdrop {
  --webform-ranking-border-radius: 0.25rem;
}
```

## Alternatives Considered

- **Declare the tokens with static values on each component's root
  class** (the first-draft approach): rejected — breaks `:root`-level
  overriding, per the pitfall above.
- **Target only Olivero, since it's the default frontend theme and
  #115 already leaned on it:** rejected — #116 explicitly asked for
  alignment with Claro and Gin too, and both are common enough
  (Claro is core's default admin theme; Gin is one of the most
  widely-installed contrib themes) that skipping them would leave real
  sites without any theme-token match at all, falling straight to the
  static default.
- **A single shared `--webform-ranking-spacing` token reused across
  both files:** rejected — matrix's narrow-viewport margin (rem-scale,
  block-level) and dragdrop's item padding/gap (em-scale, tightly
  packed inline spacing) serve different visual roles at different
  units; forcing one shared name risks a theme override in one context
  leaking unexpectedly into the other.

## Consequences & Trade-offs

### Positive

- A site's theme — or a site builder's own custom CSS — can restyle
  spacing, borders, and focus rings without fighting specificity or
  reaching for `!important`.
- The convention degrades gracefully: no matching theme token anywhere
  → static, accessible defaults; Claro/Gin present → form-appropriate
  values; Olivero present → its own scale. No hard dependency on any
  one theme.
- `--webform-ranking-border-radius`'s "never specified by module CSS"
  design meant reversing its unchained-vs-chained default in #120 was
  a pure fallback-chain edit — no admin form, no PHP, no render-layer
  change needed.

### Negative / Caveats

- Fallback chains are verbose and repeated at each point of use (no
  central `:root`-style declaration block exists to read them from in
  one place) — this ADR's table is the canonical reference instead.
- The fallback chain is a one-way improvement, not a guarantee: a theme
  that happens to define `--input-border-color` for an unrelated
  purpose (a same-named but semantically different token) would leak
  into this module's styling. Considered acceptable — these are
  genuinely common, semantically-matched names in practice (Claro/Gin's
  own real usage), and any theme wanting to opt out can still directly
  set `--webform-ranking-border-color` itself, which always wins.

## Related Code & Docs

- **Files:** `css/webform_ranking.matrix.css`, `css/webform_ranking.dragdrop.css`
- **Related:** GitHub issue #115 (the original ad hoc Olivero-token
  chaining this formalizes)
- **GitHub Issues:** #116, #10 (parent, split), #120 (theme-derived
  border-radius/N/A-opacity, reversing this ADR's original unchained-
  border-radius decision), #119 (matrix's first `--webform-ranking-
  border-color` consumer, via `accent-color` on the taken-radio state;
  an attempted `border` consumer for row/column grid lines was reverted
  after real-theme testing — see `docs/CONTINUATION.md` entry 42)
