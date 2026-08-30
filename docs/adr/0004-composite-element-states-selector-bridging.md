# ADR-0004: Per-item #states selectors for a composite ranking element

- **Status:** Accepted
- **Date:** 2026-07-10
- **Component/Subsystem:** `WebformRanking::getElementSelectorInputsOptions()`, `WebformRanking::getElementSelectorInputValue()`

## Context & Problem Statement

A `#states` condition elsewhere on the form needs to be able to target "is item A currently ranked 1st?" — but this element is composite (stores a flat item-value => rank map, not a single scalar value), and Webform's generic composite-element machinery assumes fixed, known sub-property keys (e.g. `WebformName`'s `first`/`last`). Left un-overridden, the base class's own selector-building logic falls into a different branch and offers a single bogus selector matching no real DOM input at all — confirmed live: an admin building a condition against this element (originally discovered via Likert, a similarly-shaped element) hit an "Array to string conversion" PHP warning, because the whole flat rank map reached `checkConditionTrigger()` as a single scalar-expected value.

Two separate points in Webform's own extension surface need a matching override: which selectors to *offer* in the admin's condition-builder dropdown, and how to *resolve* one of those selectors back to a real comparison value during actual server-side validation.

## Decision

**Offering selectors** (`getElementSelectorInputsOptions()`, not `getElementSelectorOptions()`): this is the actual extension point `WebformElementBase` builds around — its own `getElementSelectorOptions()` calls this method, and if it returns a non-empty array, wraps each entry as `:input[name="{$name}[{$input_name}]"]` and nests the whole set under the element's title. Overriding `getElementSelectorOptions()` directly instead (an earlier version's approach, appending to `parent::getElementSelectorOptions()`'s result) falls into the base class's *other* branch, which returns that one bogus selector matching no real DOM input.

One selector is exposed per item ("Ranking [Ranking] > Item A (rank)"), never the whole composite value at once, resolving via a different real DOM input depending on display style:

- **Matrix:** each row's radios element is already a real, individually-named DOM input (`{key}[matrix][{item}]`) — no extra data needed.
- **Drag/drop:** an item's rank has no equivalent real input of its own; it only exists as its position within a comma-joined hidden input. Selectors instead point at a second, purely-derived per-item hidden input (`{key}[dragdrop][rank][{item}]`) that `buildDragDrop()`/`element.dragdrop`'s `sync()` keep in lockstep with the real `order`/`na` inputs, specifically so `#states` has something real to bind to.

**Resolving a selector back to a value** (`getElementSelectorInputValue()`): without this override, the server-side conditions validator falls back to `WebformElementBase`'s generic composite-key extraction, which reduces stored data via `$value[$composite_key]` — but this element's selectors use the item's *value* as the third selector segment (e.g. `preference[matrix][pizza]`), not a real key in the storage map, so the generic extraction can't reduce it (the same "Array to string conversion" failure mode as above, this time server-side in `checkConditionTrigger()`). This override parses the selector's `matrix`/`dragdrop` segment and item-value segment directly, then resolves the actual value via `getItemRankValue()` against the submission's stored data — storage is unconditionally the flat matrix-shaped map regardless of display style (`validateWebformRanking()` always finishes with `canonicalToMatrix()`), so no style branching is needed once the item value is extracted.

## Alternatives Considered

- **Override `getElementSelectorOptions()` directly, appending to the parent's result:** rejected — falls into the base class's non-input branch, producing one bogus top-level selector alongside the real per-item ones, ungrouped and unlike every other composite element in Webform.
- **A trailing `[rank]` suffix on the matrix selector** (`{key}[matrix][{item}][rank]`, an earlier version of this code): rejected/fixed — never matched any real DOM input; each matrix row's radios share the row's own `#parents` directly, with no `[rank]` segment.
- **Rely on the base class's generic composite-key extraction for read-side resolution:** rejected — assumes fixed sub-property keys, not applicable to this element's per-item-value selector scheme; produces the same array-to-string failure server-side that the write-side override exists to avoid client-side.

## Consequences & Trade-offs

### Positive

- A `#states` condition can target an individual ranking item's position exactly like any other single-value form field, both in the admin condition-builder UI and during real server-side validation.
- Both display styles share one resolution mechanism (`getItemRankValue()` against the flat matrix-shaped storage), despite drag/drop needing an extra synced echo input to make it possible at all.

### Negative / Caveats

- Drag/drop's per-item rank-echo hidden input (`{key}[dragdrop][rank][{item}]`) is a second, purely-derived data path that must be kept in lockstep with the real `order`/`na` inputs on every reorder — a staleness risk confined to one write path (`buildDragDrop()`/`element.dragdrop`'s `sync()`), but a real one if that sync logic is ever changed without this in mind.
- The selector segment parsing in `getElementSelectorInputValue()` is coupled to the exact selector shape `getElementSelectorInputsOptions()` produces — changing one without the other silently breaks condition resolution with no error, only conditions that never fire.

## Related Code & Docs

- **Files:** `src/Plugin/WebformElement/WebformRanking.php` (`getElementSelectorInputsOptions()`, `getElementSelectorInputValue()`, `getItemRankValue()`), `js/webform_ranking.dragdrop.js` / `WebformRanking::buildDragDrop()` (the rank-echo hidden input)
