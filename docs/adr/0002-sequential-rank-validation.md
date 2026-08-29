# ADR-0002: Enforce sequential-from-1 rank validation to prevent live-DOM/stored-meaning divergence

- **Status:** Accepted
- **Date:** 2026-07-10
- **Component/Subsystem:** `WebformRankingConverter` / `WebformRanking::validateWebformRanking()` (matrix display style)

## Context & Problem Statement

`WebformRankingConverter::matrixToCanonical()` only preserves each ranked item's *relative order*, not the literal rank numbers a respondent picked — a skipped rank is silently "coalesced" away once canonical (ranks `2`, `3` become canonical positions `0`, `1`, i.e. 1st/2nd, once stored).

That's fine for the *stored* meaning, but while the form is still live, any `#states` condition on another element keyed to "is item X ranked 1st" checks the live DOM value directly. If a respondent picks `2` for item X without anything picked `1`, nothing is live-checked as `1` anywhere — so that condition never fires client-side, even though after submission item X would be recorded as 1st. Left unaddressed, the live form's conditional behavior and the eventual stored/reported meaning of the same submission would silently diverge.

## Decision

Enforce "the ranks in use must be exactly `{1, 2, ..., N}`, no skipped rank" at validation time (`WebformRanking::validateWebformRanking()`), rejecting a skipped rank with a validation error instead of silently accepting and coalescing it. `WebformRankingConverter::matrixRanksAreSequential()` implements the check directly against the raw, pre-coalesce submitted rank strings — deliberately not against the already-coalesced canonical shape, since the coalescing itself is what would hide the gap.

## Alternatives Considered

- **Accept skipped ranks, rely on the coalesced canonical value everywhere (including live `#states` conditions):** rejected — would require rewriting how `#states` conditions target a rank position to check canonical position instead of the literal submitted DOM value, which conflicts with how Drupal's own `#states`/`states.js` evaluates client-side values directly. Not something this module can change from the outside.
- **Silently renumber/coalesce ranks server-side without rejecting:** rejected — defers the same live-DOM divergence problem rather than solving it; the discrepancy between what's checked live in the browser during the form session and what ends up stored would still exist.

## Consequences & Trade-offs

### Positive

- A live `#states` condition targeting a specific rank position stays consistent with the eventual stored/reported meaning of the same submission — no silent divergence between what a respondent sees react conditionally during the form and what the stored result actually says.
- The check runs against raw, pre-coalesce input, so it can't be fooled by the same coalescing behavior it exists to guard against.

### Negative / Caveats

- A respondent who skips a rank (e.g. picks 2nd without ever picking 1st) gets a validation error rather than having their ranking silently "fixed" — an intentional trade-off, but worth flagging: some other Drupal ranking/ordering widgets auto-renumber instead of rejecting.
- This check is matrix-display-style-specific (drag/drop's ordering is inherently gapless by construction) — `sequential_ranks_error` (the admin-facing override message for this check) is still offered ungated regardless of display style, a separate, smaller trade-off documented at that property's own definition in `WebformRanking::form()`.

## Related Code & Docs

- **Files:** `src/WebformRankingConverter.php` (`matrixRanksAreSequential()`, `matrixToCanonical()`), `src/Plugin/WebformElement/WebformRanking.php` (`validateWebformRanking()`, `sequential_ranks_error` property)
