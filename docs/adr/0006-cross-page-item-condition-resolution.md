# ADR-0006: Statically resolve an item's cross-page #states condition server-side

- **Status:** Accepted
- **Date:** 2026-08-21
- **Component/Subsystem:** `WebformRanking::resolveCrossPageItemStates()`, `itemConditionIsCrossPage()`, `buildMatrix()`/`buildDragDrop()`'s `_cross_page_hidden` handling

## Context & Problem Statement

An item's own `#states` condition (distinct from this element's own top-level `#states`) never worked when its trigger element lived on an earlier wizard page. Root cause: Webform's own cross-page condition handling (`WebformSubmissionConditionsValidator::buildForm()`, which rewrites a cross-page trigger's selector so `states.js` has something live to bind to, or pre-resolves it statically when it can't) walks the *configured* element tree before `\Drupal::formBuilder()` even starts processing `#process` callbacks — before `processWebformRanking()` has expanded `#items` into real, independently-discoverable sub-elements at all. An item's condition is invisible to that walk no matter what, so it never gets the same cross-page treatment this element's own top-level `#states` does (GitHub issue #61).

## Decision

Replicate that treatment narrowly, for items whose condition is specifically cross-page. `itemConditionIsCrossPage()` detects this by walking `$complete_form` for the referenced element's accessibility — confirmed empirically (live reproduction, not inferred) that each non-current wizard page's `#access` is already correctly set to `FALSE` by the time this `#process` callback runs. Once detected, the condition is resolved *once* server-side via the same `WebformRankingVisibilityResolver` validation already trusts, and applied statically: the item's `states` array is cleared (nothing left to attach live), and if resolved not-visible, an internal `_cross_page_hidden` marker is set instead. `buildMatrix()`/`buildDragDrop()` use that marker to exclude the item via `#access` rather than a live `#states` attachment — correct, since there's nothing on the current page that could ever change the trigger's value anyway, making live JS reactivity for that item meaningless.

Same-page conditions (or no condition at all) are left completely untouched — this only ever narrows behavior for the confirmed cross-page case. A selector this detection can't resolve at all (a typo, or an unrecognized shape) falls through to `FALSE` (treated as same-page), preserving existing live `#states` attachment rather than guessing; an unresolvable selector already has its own separate fail-open handling at actual validation time.

## Alternatives Considered

- **Do nothing, accept cross-page item conditions as a known limitation:** rejected — silently broken conditional logic is worse than the complexity of a targeted fix, especially since this element's own top-level `#states` already gets correct cross-page treatment; the gap was specific to per-item conditions and confusing without a fix.
- **Guess at cross-page-ness from selector shape alone, without walking `$complete_form`:** rejected — no reliable way to determine "which page is this selector's target on" without actually inspecting the built form tree's per-page `#access` state.

## Consequences & Trade-offs

### Positive

- Per-item conditions referencing an earlier wizard page's trigger now work exactly as an admin would expect, matching this element's own top-level `#states` behavior for the same scenario.
- Same-page conditions are provably untouched by this logic (an unresolvable selector falls back to existing behavior), so the fix can't regress anything already working.

### Negative / Caveats

- This mechanism depends on `$complete_form`'s per-page `#access` already being correctly set by the time `processWebformRanking()`'s `#process` callback runs — an implicit ordering dependency on Drupal's own form-building/wizard-page pipeline, verified empirically rather than guaranteed by any documented API contract.
- `_cross_page_hidden` is a second "is this item excluded" signal alongside the resolver's own live check, and both `buildMatrix()` and `buildDragDrop()` must remember to check it — a future new render style would need to replicate this handling.

> **Correction (2026-09-21, GitHub #152, related to #59):** `buildMatrix()`'s `_cross_page_hidden` handling set `#access = FALSE` on the item's cells (label, each rank radio) but never on the row itself — unlike `buildDragDrop()`'s equivalent, which sets `#access` directly on the actual `<li>` render element (correctly omitted by normal rendering), a matrix item's row is a `Table`-element pseudo-row, not a real render element of its own. `Table::preRenderTable()` (`web/core/lib/Drupal/Core/Render/Element/Table.php`) discovers row keys via `Element::children($element)` — which is not access-aware — and only ever reads a row's own `#attributes` to build the `<tr>`; it never consults `#access` at the row level at all. The result: a cross-page-hidden item's `<tr>` stayed in the DOM with every cell access-denied (rendering as `<td></td>`), an empty, still-padded row — the same fundamental symptom #59/ADR-0012 already fixed for a *same-page* condition (`toggleRow()` setting `hidden` on the row, since per-cell `#states` alone has the identical row-survives problem), just never carried over to this cross-page, `#access`-based exclusion path added later for #61. Confirmed directly against a real production webform (nested trigger field, intervening wizard page, element-level `#states` on the ranking element itself all reproduced faithfully; the resulting Kernel test passed even so, proving the gap was specifically the row-level omission, not anything structural about the config). Fixed by also setting `$element['matrix'][$row_key]['#attributes']['hidden'] = 'hidden'` alongside the existing per-cell `#access` — the exact same attribute/CSS rule (`tr[hidden] { display: none !important; }`) `toggleRow()` already relies on for #59's same-page case, just applied directly server-side here since a cross-page item is already statically resolved and nothing client-side needs to react to it.

## Related Code & Docs

- **Files:** `src/Element/WebformRanking.php` (`resolveCrossPageItemStates()`, `itemConditionIsCrossPage()`, `extractConditionSelectors()`, `extractSelectorsRecursive()`, `isWebformKeyAccessible()`, `buildMatrix()`/`buildDragDrop()`'s `_cross_page_hidden` checks)
- **Tests:** `WebformRankingCrossPageItemStatesTest` (Kernel), `WebformRankingCrossPageItemStatesJavaScriptTest` (2026-09-21 row-hiding assertions added for the correction above)
- **GitHub Issues:** #57 (element-level #states, related but separate), #59 (the same-page version of this exact empty-row problem, fixed earlier via `toggleRow()` — see ADR-0012), #61 (this ADR's own fix), #152 (row-hiding correction above)
