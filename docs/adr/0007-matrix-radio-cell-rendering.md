# ADR-0007: Matrix rows: per-column radio cells, container wrapper, native-required/#states interplay

- **Status:** Accepted
- **Date:** 2026-08-21
- **Component/Subsystem:** `WebformRanking::buildMatrix()`

## Context & Problem Statement

The matrix display style needed each ranking item as its own table row, with one radio per rank column (so "each item gets exactly one rank" is a natural constraint of separate mutually-exclusive radio groups, not something enforced against the grain of the markup), fully accessible, and correctly reactive to per-item `#states` conditions. Three separate, non-obvious rendering constraints had to be satisfied together:

1. A `#type => 'radios'` bundle renders all its options inside one shared wrapper — using it per row stacked every rank option in the row's single cell instead of spreading across rank columns, defeating the table layout entirely.
2. `Renderer::doRender()` processes `#pre_render` callbacks *before* it processes `#states` (confirmed by reading `Renderer.php` directly). A `#type => 'html_tag'` label wrapper bakes `#attributes` into a fixed markup string via its own `#pre_render`, before `data-drupal-states` is ever added — so a hidden-when-conditional row's label would render with no `#states`-driven attribute anywhere, unable to hide alongside its own row.
3. A native HTML `required` attribute baked in unconditionally onto a row's radios, when that row is later hidden client-side by its own `#states` condition, leaves a `required`, unfocusable, invisible control the browser can never let the user satisfy — it silently refuses to submit, with no Drupal-level or visible error anywhere (GitHub issue #68).

## Decision

**Row label wrapper:** `#type => 'container'`, not `html_tag`. `container.html.twig` reads `#attributes` via `#theme_wrappers`, at theme-render time — *after* `#states` processing — the same pattern `Container`'s own class docblock shows as the canonical way to combine `#states` with a wrapper element. The radio cells themselves never had this problem, since `#type => 'radio'` is itself a themed form input, not a `#pre_render`-baked one.

**One rank column per cell:** one real `radio` element built directly per rank column (not a `radios` bundle), each keyed separately as its own array entry. `Table::preRenderTable()` turns each of a row's *direct* child elements into its own `<td>` in insertion order, so this is what actually lines each button up under its own header column. Mirrors core's own `Radios::processRadios()` construction pattern (`#return_value`/`#default_value`/`#parents`/`#id`) rather than reinventing it — every option sharing the row's `#parents` is what makes them one mutually-exclusive native radio group despite living in separate `<td>`s.

**Native `required`, mirrored through `#states` when the row is conditional:** the plain HTML `required` attribute gets the browser's own "at least one radio in this group must be checked" constraint validation for free, without engaging `FormValidator`'s per-element required check (which operates on a single radio, not the whole row, and would conflict with this element's own `#required_all` validation). For a row with a live, same-page condition, the static attribute is withheld entirely and instead mirrored into the row's own `#states` array as `required`/`optional` (`optional` is core's own alias for `!required`, exactly parallel to `invisible` being `!visible` — `Drupal.states.State.aliases`) — so `states.js`'s existing `state:required` handler adds/removes the attribute itself, in lockstep with visibility, both on page load and on every live change. A row with no live per-page condition (including a cross-page one already resolved and excluded via `#access`) keeps the plain static attribute, since there's nothing there to ever desync from.

> **Superseded by ADR-0018 (2026-08-30):** the mirror above crashed submission (GitHub #102) — Webform core's own conditions validator resolves a Webform element plugin for any `#states`-carrying element with a `required`/`optional` key, which a bare `radio`/`container` doesn't have. The static attribute is now permanently withheld on a conditional row instead of being mirrored/re-added. The rest of this ADR's decisions (container wrapper, per-column radio cells) are unaffected.

## Alternatives Considered

- **`#type => 'radios'` per row:** rejected outright — every option renders stacked in one cell, not spread across rank columns.
- **`#type => 'html_tag'` for the row label:** rejected — its `#pre_render` bakes `#attributes` before `#states` processing ever runs, leaving a conditionally-hidden row's label with no `data-drupal-states` to hide by.
- **Always bake a static `required` attribute, ignore the conditional-row case:** the original behavior, and the actual bug (GitHub issue #68) — a hidden-but-required row silently blocked submission with no error anywhere. Rejected once found.
- **Client-side JS toggling the `required` attribute directly on visibility change:** not pursued — reusing `states.js`'s own existing `required`/`optional` state handling (already loaded, already correct) needed no new JS at all, just the right server-rendered `#states` shape.

## Consequences & Trade-offs

### Positive

- Matrix rows render correctly under the table's column structure with no bundle-stacking bug, remain accessible (`role="radiogroup"`/`aria-labelledby`/`aria-describedby`), and stay reactive to `#states` for visibility.
- ~~The required/optional mirror reuses `states.js`'s own built-in aliasing rather than a second, independently-timed JS binding — one source of truth for when the attribute changes.~~ No longer applicable — see the ADR-0018 supersession note above.

### Negative / Caveats

- The `container`-vs-`html_tag` distinction (and *why* it matters) is non-obvious from the code alone — a future label-wrapper change that swaps back to `html_tag` would silently break conditional-row hiding for the label specifically, with no error, only a visibly orphaned label above a hidden row.
- ~~The required/optional mirror is gated on `$required_all` specifically...~~ — the static-required suppression (post-ADR-0018) is still gated on `$required_all`, same as before; only the live re-add via `#states` is gone.

## Related Code & Docs

- **Files:** `src/Element/WebformRanking.php` (`buildMatrix()`)
- **Superseded by:** ADR-0018 (native-required/#states interplay decision only)
- **GitHub Issues:** #46 (accessible matrix markup), #68 (required-vs-conditional-row fix, superseded), #102 (crash fix, see ADR-0018)
