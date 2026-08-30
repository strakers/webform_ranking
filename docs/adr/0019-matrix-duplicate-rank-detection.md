# ADR-0019: Detecting and preventing silent duplicate-rank collisions on conditional matrix items

- **Status:** Accepted
- **Date:** 2026-08-30
- **Component/Subsystem:** `js/webform_ranking.matrix.js`, `WebformRankingConverter`, `WebformRanking::validateWebformRanking()`

## Context & Problem Statement

On the matrix display style, an item hidden via its own condition could retain a stale rank selection. Because the "steal" mechanism (ADR-0011) deliberately skips stealing a rank from a currently-hidden row, a different, visible item could validly take that same rank while the first item stayed hidden. If the hidden item later became visible again, both items showed the same rank — a state never reachable while both items were visible at once, but reachable via hide/show timing (GitHub #104).

Submitting in this state didn't produce a "duplicate rank" error. `WebformRankingConverter::matrixToCanonical()` builds the canonical value by keying an intermediate array on rank number; a genuine duplicate silently collides there, and the later-processed item wins — the other is dropped entirely, not flagged. The resulting canonical value was simply missing the dropped item, which correctly-but-misleadingly tripped `#required_all`'s "every item must be ranked" check instead of describing the real problem.

Two existing checks were assumed, per their own code comments, to catch this and don't:
- `count($values) !== count(array_unique($values))` in `validateWebformRanking()` runs *after* `matrixToCanonical()`'s collision has already erased the duplicate.
- `matrixRanksAreSequential()` only tracks which rank *numbers* are used (dedup via array key), blind to how many items claim each one.

ADR-0012 itself states the same false premise: "server-side validation already discards a hidden item's stale selection regardless of what the row looks like" — it doesn't; nothing discarded it, the collision silently ate it.

## Decision

Two complementary fixes, not either/or — closing the interactive path alone would leave this module's server-side validation trusting client behavior it can't actually guarantee (e.g. non-JS submission, or other integrations built on top of this element):

**Client-side (`js/webform_ranking.matrix.js`):** an item's currently-checked radio (rank or N/A) is unchecked the moment it transitions to hidden, in the same `state:visible` listener already handling row/column visibility — not deferred until the item is shown again, so `markTakenRanks()`'s "taken" hint stays accurate for the entire time the item is hidden. A `change` event is dispatched on the unchecked input, mirroring the existing rank-steal pattern (ADR-0011) exactly, so any `#states` condition elsewhere watching this item's rank still re-evaluates.

**Server-side (`WebformRankingConverter::matrixRanksHaveNoDuplicates()`, called from `validateWebformRanking()`):** reads the raw per-item input directly, before `matrixToCanonical()`'s collision can hide a duplicate, and rejects with a message naming the actual problem. Inserted *before* the existing `#required_all` check in code order — load-bearing, not cosmetic: `Drupal\Core\Form\FormState::setErrorByName()` only keeps the *first* error set for a given element, so this ordering is what makes the correct message win instead of the misleading one.

## Alternatives Considered

- **Client-side fix only:** rejected — leaves the server trusting client-side behavior for correctness, which this module has no way to guarantee (a non-JS submission, or any other producer of raw matrix input this element wasn't specifically tested against).
- **Fix `matrixToCanonical()` itself to reject instead of silently colliding:** rejected — conflates two responsibilities the codebase already keeps separate elsewhere (`matrixRanksAreSequential()` already established the pattern of a dedicated raw-input check feeding into `validateWebformRanking()`, rather than validation logic living inside the normalization/conversion layer).
- **Clear only the numeric rank on hide, leave N/A selections alone:** rejected — N/A can't cause this specific collision (tracked separately, no rank-keyed collision mechanism), but leaving it inconsistent with rank-clearing would be an arbitrary asymmetry against the same underlying principle (a hidden item shouldn't retain a stale selection that can resurface later).

## Consequences & Trade-offs

### Positive

- The collision state is no longer reachable through normal interaction at all.
- Server-side validation now correctly rejects a duplicate rank regardless of how it arrived, with a message describing the actual problem instead of a misleading one.

### Negative / Caveats

- A respondent whose item gets hidden loses that item's selection, even if the hide is only momentary (e.g. a trigger field's value flickers). This matches the same trade-off already accepted for the row's *visibility* itself — nothing new, but worth having in mind alongside this change.
- The check-ordering dependency in `validateWebformRanking()` (this check must run before `#required_all`) is implicit in code position, not enforced structurally — a future reordering could silently reintroduce the misleading-message symptom without reintroducing the underlying data loss. No stronger guard was added for this, matching how `matrixRanksAreSequential()`'s own ordering relative to other checks is already handled the same implicit way.

## Related Code & Docs

- **Files:** `js/webform_ranking.matrix.js`, `src/WebformRankingConverter.php` (`matrixRanksHaveNoDuplicates()`), `src/Element/WebformRanking.php` (`validateWebformRanking()`)
- **Related:** ADR-0011 (rank reassignment — the steal-skips-hidden-rows behavior that makes this collision reachable), ADR-0012 (matrix conditional-item visibility sync — corrected pointer note added for its incorrect claim)
- **GitHub Issues:** #104
