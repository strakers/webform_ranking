# ADR-0010: Validation builds a live entity for #states, and always writes back the canonical value

- **Status:** Accepted
- **Date:** 2026-08-20
- **Component/Subsystem:** `WebformRanking::validateWebformRanking()`

## Context & Problem Statement

Server-side validation recomputes the visible-item set via `WebformRankingVisibilityResolver`, which needs the submission's current field values to evaluate each item's `#states` condition against. Two separate correctness gaps surfaced during QA hardening:

1. `$form_state->getFormObject()->getEntity()` returns whatever entity object is currently attached to the form state — at validation time, that has **not yet** been synced with this request's submitted field values (that copy happens later, in submit/build-entity handling). Its data can be entirely stale or empty for fields a `#states` condition needs to evaluate against, incorrectly treating a truly-visible item as invisible and silently dropping its rank. This was most visible during a `webform_computed_twig` element's `#ajax` recompute, which triggers a full validation pass on every change elsewhere on the form.
2. `webform_computed_twig`'s live-recompute path reads `$form_state->getValues()` directly via `WebformSubmissionForm::copyFormValuesToEntity()` to build a throwaway `WebformSubmission` for its own Twig template — bypassing this element's plugin entirely, with no shape conversion of its own. If validation only wrote the canonical `{values, na}` shape back on a fully-passing run (an earlier version's behavior on some checks), that temporary submission would see this element in canonical shape instead of the flat map every other consumer (including a Twig token like `data.ranking.pizza`) expects, for as long as the element was in any invalid, not-yet-resolved interim state.

## Decision

**Live entity, not cached:** `$form_object->buildEntity($complete_form, $form_state)` builds a fresh entity from the *current* `$form_state` values, exactly matching the pattern Webform's own generic element validator uses for this same purpose (`WebformSubmissionConditionsValidator::elementValidate()`). Used both here and in `resolveCrossPageItemStates()`, for the same reason.

**Unconditional write-back:** every validation check in this method sets an error (if any) without an early `return`, specifically so the final `$form_state->setValueForElement($element, WebformRankingConverter::canonicalToMatrix(...))` call always runs — even on a failed validation pass. The value is always converted to the flat item-value => rank shape (never left canonical), matching what every consumer including `webform_computed_twig`'s bypass path expects.

## Alternatives Considered

- **`getEntity()` instead of `buildEntity()`:** rejected — returns a cached entity not yet synced with the current request's submitted values, giving `#states` evaluation stale data specifically during the same-request scenario that matters most (a trigger field just changed on this same submission).
- **Only write back the canonical-to-flat conversion on a fully-passing validation run:** the earlier behavior on some checks, and the actual bug — left the element in canonical shape during any invalid interim state, corrupting `webform_computed_twig`'s live-recompute Twig context (a flat map expected, an array-of-arrays received) for as long as that state persisted.
- **Have `webform_computed_twig` go through this element's plugin instead of reading `$form_state->getValues()` directly:** not this module's call — `copyFormValuesToEntity()`'s behavior is core Webform's own, not something `webform_ranking` can change; working around it here was the only available fix.

## Consequences & Trade-offs

### Positive

- `#states` conditions on ranking items evaluate correctly even when their trigger was just changed in the same request (same-page AJAX recompute, multi-field validation passes).
- The flat-shape write-back invariant holds unconditionally, so any consumer reading this element's value mid-validation (not just after a clean pass) sees the correct, expected shape — closing the `webform_computed_twig` corruption gap for good rather than only in the common case.

### Negative / Caveats

- Every future validation check added to this method must remember not to `return` early — an early return would silently reintroduce the unconditional-write-back gap for whatever checks come after it, with no test failure unless the specific `webform_computed_twig`-recompute scenario is exercised.
- `buildEntity()` is a heavier operation than reading a cached entity; called on every validation pass (including `webform_computed_twig`'s frequent recomputes), though not observed to be a practical performance concern.

## Related Code & Docs

- **Files:** `src/Element/WebformRanking.php` (`validateWebformRanking()`, `resolveCrossPageItemStates()`), `src/WebformRankingConverter.php` (`canonicalToMatrix()`)
