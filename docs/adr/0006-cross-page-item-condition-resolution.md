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

## Related Code & Docs

- **Files:** `src/Element/WebformRanking.php` (`resolveCrossPageItemStates()`, `itemConditionIsCrossPage()`, `extractConditionSelectors()`, `extractSelectorsRecursive()`, `isWebformKeyAccessible()`, `buildMatrix()`/`buildDragDrop()`'s `_cross_page_hidden` checks)
- **GitHub Issues:** #57 (element-level #states, related but separate), #61 (this fix)
