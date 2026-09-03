# ADR-0022: Inline SVG icons via nested `html_tag` render arrays

- **Status:** Accepted
- **Date:** 2026-09-03
- **Component/Subsystem:** `src/Element/WebformRanking.php` (`buildDragDrop()`, `buildMoveIcon()`)

## Context & Problem Statement

GitHub issue #117 asked to replace the drag/drop move buttons' `▲`/`▼`
text glyphs with real icons. This element is built entirely from PHP
render arrays — no Twig template renders its markup — so the icon
needed to be produced the same way, and needed to preserve the existing
accessibility pattern (glyph/icon wrapped in an `aria-hidden` span
inside the button, `aria-label` carrying the real accessible name).

No prior SVG usage exists anywhere in `src/`, so the render-array
approach wasn't already established in this codebase.

## Decision

Build the icon as **nested `html_tag` render arrays** — an `#tag =>
'svg'` element with a nested `#tag => 'path'` child — rather than a raw
markup string.

This works because Drupal core's `HtmlTag` render element
(`Drupal\Core\Render\Element\HtmlTag`) already extends its
`$voidElements` list beyond the standard HTML void tags to include
`rect`, `circle`, `polygon`, `ellipse`, `stop`, `use`, `path` — core has
deliberately provisioned `html_tag` for building inline SVG this way.
An `#tag => 'svg'` element is *not* void, so it renders its child
render-array elements as normal nested content; `#tag => 'path'` *is*
void, so it self-closes using only its `#attributes` (e.g. `d`, `fill`)
with no `#value` needed.

Confirmed via `Drupal\Core\Render\Renderer::doRender()`: when an
element has no `#theme` and no preset `#children`, child render-array
keys are rendered into `#children` regardless of `#markup`; an empty
`#markup` (what `HtmlTag::preRenderHtmlTag()` sets when `#value` is
`NULL`) is simply prepended as an empty string ahead of the children's
output. So an `svg` element with `#value` omitted and a nested `path`
child renders exactly as expected — no special-casing required.

## Alternatives Considered

- **A raw SVG markup string via `#value`:** rejected. `HtmlTag`'s
  `#value` handling runs any plain string through
  `Xss::filterAdmin()`, whose allowed-tag list doesn't include `svg` or
  `path` — the icon would simply get stripped. Marking the string safe
  via `Markup::create()` would bypass that (technically works, since
  the content is fully static, not user input), but breaks with how
  every other element in this codebase is built — render arrays, not
  hand-assembled HTML strings — for no real benefit.
- **A `#theme` render callback / Twig template for the icon:** rejected
  as unnecessary ceremony for a two-path shape used in exactly two
  places; the plain render-array form is self-contained and just as
  readable.
- **A third-party icon set (Feather, Heroicons, etc.):** rejected — the
  replaced glyphs were a plain filled triangle each; pulling in a design
  system for a shape this simple adds a dependency/licensing
  consideration for no real gain. A hand-drawn two-point triangle path
  is simpler and license-free.

## Consequences & Trade-offs

### Positive

- Consistent with how the rest of this element is built — no new
  rendering pattern (raw markup strings, `#theme` callbacks) introduced
  for a single feature.
- `fill="currentColor"` means the icon needs no color token of its
  own — it always matches the button's text color, theme-adaptive for
  free.
- Establishes a reusable technique (`buildMoveIcon()`'s shape) if
  #119/#120 or any future work wants icons elsewhere in this module.

### Negative / Caveats

- Nested render arrays for SVG are more verbose than a literal markup
  string would be — acceptable for a two-path icon; would become
  unwieldy for a genuinely complex illustration (not a concern this
  module is likely to hit).

## Related Code & Docs

- **Files:** `src/Element/WebformRanking.php` (`buildMoveIcon()`)
- **Related:** [ADR-0021](0021-css-custom-property-convention.md) (the
  sizing token the icon's `1em` dimensions scale with, via #118)
- **GitHub Issues:** #117
