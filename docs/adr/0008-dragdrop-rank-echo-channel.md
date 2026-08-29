# ADR-0008: Drag/drop per-item rank-echo hidden input for #states targeting

- **Status:** Accepted
- **Date:** 2026-07-10
- **Component/Subsystem:** `WebformRanking::buildDragDrop()` (the `dragdrop.rank.*` hidden inputs)

## Context & Problem Statement

The drag/drop display style's authoritative submitted value is two comma-joined hidden inputs (`order`, `na`), kept in sync client-side on every reorder. But `#states`'s trigger vocabulary (`value`/`pattern`/etc.) has no way to index into a CSV string and ask "is item X at position 1?" — a `#states` condition elsewhere on the form that needs to target a specific item's rank has nothing real to bind to, the same underlying problem `getElementSelectorInputsOptions()`/`getElementSelectorInputValue()` solve server-side for validation (see `docs/adr/0004-composite-element-states-selector-bridging.md`).

Separately, while building this, a real bug surfaced during browser testing: a `querySelector` meant for an item's own container instead matched a *different* element — a hidden rank input rendering earlier in the DOM tree — because both used the same generic `data-webform-ranking-value` attribute pattern, and the query matched whichever happened to come first.

## Decision

A second, purely-derived per-item hidden input (`dragdrop.rank.{item_value}`) is rendered for every item, giving `#states` a real, individually-named DOM value to target — mirroring `buildMatrix()`'s one-radio-per-item approach. It is emphatically **not authoritative**: `WebformRankingConverter::dragdropToCanonical()` only ever reads `order`/`na`; this channel is never consulted for storage or validation. It is kept in sync *only* by `element.dragdrop`'s own `sync()` function, in lockstep with every `order`/`na` write — any new code path that mutates `order`/`na` without going through `sync()` desyncs this channel silently (`#states` would show a stale rank while the actually-submitted value stays correct, since storage never reads this input at all).

The rank-echo input's own tracking attribute is deliberately `data-webform-ranking-rank-for`, a different attribute name than the item container's own `data-webform-ranking-value` — not just a different element. A shared generic attribute name would let a `querySelector` match whichever of the two elements happens to come first in DOM order, silently picking the wrong node (the real bug encountered while testing).

## Alternatives Considered

- **No rank-echo channel; tell admins `#states` can't target drag/drop rank:** rejected — drag/drop and matrix are meant to be functionally equivalent display styles for the same element; an admin switching styles shouldn't lose the ability to build the same conditions.
- **Reuse the same `data-webform-ranking-value` attribute name for both the item container and the rank-echo input:** tried, rejected — ambiguous selector matching caused a real bug (a JS lookup for the item container matched the rank-echo input instead).
- **Make the rank-echo channel authoritative (read it for storage/validation instead of `order`/`na`):** rejected — would mean two representations of the same data with no clear single source of truth; keeping `order`/`na` as the only authoritative channel, with the echo purely derived and disposable, is simpler to reason about.

## Consequences & Trade-offs

### Positive

- `#states` conditions can target a specific drag/drop item's rank exactly as they can a matrix item's, via a real, individually-addressable DOM input.
- The distinct attribute-naming convention (`data-webform-ranking-rank-for` vs. `data-webform-ranking-value`) closes off the exact selector-ambiguity bug that was hit once already.

### Negative / Caveats

- This is a second, non-authoritative data channel that must be kept perfectly in lockstep with `order`/`na` by a single JS function (`sync()`) — any future code path that updates `order`/`na` directly without calling `sync()` silently desyncs `#states` from the real submitted state, with no error, only conditions that stop matching correctly.
- The rank-echo mechanism only exists because of a real gap in `#states`'s trigger vocabulary (no way to index a CSV string) — it isn't something this module can "fix" upstream, only work around per-element.

## Related Code & Docs

- **Files:** `src/Element/WebformRanking.php` (`buildDragDrop()`), `src/Plugin/WebformElement/WebformRanking.php` (`getElementSelectorInputsOptions()`/`getElementSelectorInputValue()`, ADR-0004), `js/webform_ranking.dragdrop.js` (`sync()`)
