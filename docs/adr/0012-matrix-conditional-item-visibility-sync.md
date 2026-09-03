# ADR-0012: Matrix client-side sync for conditionally-hidden items — whole-row hiding and rank-column trimming

- **Status:** Accepted
- **Date:** 2026-08-23
- **Component/Subsystem:** `js/webform_ranking.matrix.js` (`initMatrix()`'s visibility seeding, `toggleRow()`, `updateRankColumns()`)

## Context & Problem Statement

`WebformRanking::buildMatrix()` applies a conditionally-visible item's own `#states` to each cell's *content* individually (the label `<div>`, each radio) — `states.js` has nothing to attach to on the row (`<tr>`) itself, since `Table::preRenderTable()`'s row-attributes-to-`<tr>` merge happens during `#pre_render`, before `#states` processing ever adds `data-drupal-states` (the same timing constraint documented for why the row label needed a `container` wrapper — see ADR-0007 in `src/**`). Left alone, a hidden item's `<tr>` stays in the DOM: empty-looking, but present and taking up a table row (GitHub issue #59).

Separately, rank columns (1st, 2nd, 3rd, ... + N/A) are built server-side from the *full configured* item count and never recomputed — hiding one item via its own condition still left every other, still-visible item offering the full original set of rank positions (e.g. 3 configured items, one hidden, leaves 2 visible items each still offering "1st/2nd/3rd" instead of just "1st/2nd") (GitHub issue #60).

## Decision

**Whole-row hiding (`toggleRow()`):** the native `hidden` IDL attribute on the item's `<tr>`, not a CSS class or inline `display` — equivalent to `states.js`'s own plain `visible`/`invisible` hiding (not the `slide` variants; this element's own admin condition picker only ever offers plain `visible`/`invisible`), and one property assignment rather than a stylesheet dependency. Purely a display fix: server-side validation already discards a hidden item's stale selection regardless of what the row looks like.

> **Correction (2026-08-30, see ADR-0019):** the last sentence above was wrong — nothing discarded a hidden item's stale selection. It could silently collide with another item's rank once the hidden item became visible again (GitHub #104). `toggleRow()` now clears the selection itself on hide, and `WebformRankingConverter::matrixRanksHaveNoDuplicates()` rejects the collision server-side regardless of client behavior.

> **Correction (2026-09-03, see ADR-0022, GitHub #123):** the `offsetParent === null` seeding this ADR describes was applied unconditionally to every row, not just rows with their own per-item `#states`. `offsetParent` is also `null` when *this element's own top-level* `#states` is what's currently hiding the whole table (cross-page trigger, or any other same-page trigger) — in that case every row's `offsetParent` reads `null` at first attach regardless of any per-item condition, so every row was wrongly marked hidden, permanently (this runs once, via `once()`, and nothing re-triggers it once the element's own visibility later resolves true). `initMatrix()` now only consults `offsetParent` for a row whose first radio actually carries `data-drupal-states` (i.e. genuinely has its own per-item condition) — a row with no condition of its own is unconditionally visible, since nothing could ever have hidden it individually.

**Rank-column trimming (`updateRankColumns()`):** hides (same native `hidden` attribute) header cells and body cells for rank positions beyond what the currently-visible item count needs, computed from the first row's own radio list (every row shares the same rank set, so this needs no assumption about header markup). Purely presentational — rank-exclusivity (`markTakenRanks()`) and server-side validation (`WebformRankingConverter::matrixRanksAreSequential()`) already operate on the visible item set only, so narrowing what's *offered* doesn't change what's *valid*. The N/A column is never affected by this, since N/A isn't tied to the visible item count. At least one rank column always stays available even if every item is currently hidden — an edge case with nothing meaningful to rank, but an entirely columnless table would be worse.

**Initial-state seeding:** both mechanisms need to get their very first render right, not just react to later live changes. `Drupal.behaviors.states` (a declared library dependency) and this behavior attach during the same page-load pass, states.js first — by the time this behavior runs, a conditionally-hidden row's cells are already hidden, but the `state:visible` event that announced it fired *before* this behavior's own listener existed to catch it. `offsetParent === null` (a plain, well-supported "is this currently hidden" check, after the fact) seeds the initial `visible` map from each row's already-applied result instead — the same technique `webform_ranking.dragdrop.js`'s own position-numbering already relies on for the same reason.

## Alternatives Considered

- **Apply `#states` to the `<tr>` element directly instead of per-cell:** not viable — confirmed via the render pipeline timing that a `<tr>`'s attributes are finalized before `#states` processing ever runs; there's no way to get `data-drupal-states` onto the row itself from the server side.
- **CSS class or inline `display: none` for row/column hiding, instead of the native `hidden` attribute:** rejected — the native attribute matches exactly what `states.js` itself does for plain visible/invisible states, avoiding a parallel hiding mechanism with its own specificity/stylesheet dependency.
- **Only react to live `state:visible` events, skip initial-state seeding:** rejected — would render the very first page load incorrectly for any item already conditionally hidden, only self-correcting after the first live trigger change.

## Consequences & Trade-offs

### Positive

- A conditionally-hidden item's row (and any rank columns no longer needed once fewer items are visible) genuinely disappears from the table, not just its label/radio content — matching what an admin configuring the condition would expect.
- Correct on first render, not just after a live change, without needing any special-cased "initial load" code path distinct from the live `state:visible` handler.

### Negative / Caveats

- The `offsetParent === null` seeding trick is an "after the fact" inference, not a direct read of `states.js`'s own internal condition state — it depends on `states.js` having already run and hidden the element by the time this code executes, an implicit attach-order dependency (declared via the library dependency, but not something the browser enforces beyond that declaration).
- `updateRankColumns()`'s "at least one column always visible" floor is a special case a future change to the visible-item-counting logic could accidentally remove, leaving a genuinely columnless table when every item happens to be hidden.

## Related Code & Docs

- **Files:** `js/webform_ranking.matrix.js` (`initMatrix()`, `toggleRow()`, `updateRankColumns()`)
- **GitHub Issues:** #59 (row hiding), #60 (rank-column trimming), #104 (see ADR-0019 for the correction above)
