# ADR-0011: Reassign ("steal") a rank on conflict instead of disabling radios

- **Status:** Accepted
- **Date:** 2026-08-21
- **Component/Subsystem:** `js/webform_ranking.matrix.js` (`initMatrix()`'s `change` handler, `markTakenRanks()`)

## Context & Problem Statement

The matrix display style needs "each rank used at most once" enforced client-side as a UX convenience (server-side `WebformRanking::validateWebformRanking()` re-checks the same rule regardless, since client state is trivially bypassable). An earlier version disabled a rank's radio in every other row once it was selected elsewhere. That approach has a structural dead end a native `disabled` `<input>` can never support: once every item held a distinct rank, swapping any two items' ranks requires two simultaneous changes, and each one is blocked by the other's `disabled` state — a fully-ranked matrix could never be rearranged again at all (not even via an N/A escape hatch if `#allow_na` was off).

## Decision

Reassign ("steal") rather than block: selecting a rank already held by another (visible) row silently un-checks it there, rather than preventing the new selection. `markTakenRanks()` is purely informational — it adds a visual "taken" hint class, but every radio stays enabled and clickable regardless, which is exactly what makes stealing possible: a genuinely `disabled` input couldn't be clicked to steal its rank back. N/A is exempt from stealing — multiple items can be marked N/A simultaneously; it isn't a shared, exhaustible resource the way a numeric rank is.

The bumped row's radio is unchecked via a plain property assignment, which fires no native `change` event on its own — but `states.js` only re-evaluates a `value`-trigger `#states` condition on `change`/`keyup`. Without an explicit `dispatchEvent(new Event('change', {bubbles: true}))` on the bumped input, any `#states` condition elsewhere on the form watching specifically for that item's rank (e.g. "show X when Pizza is ranked 1st") would never re-evaluate once Pizza is bumped, staying stuck showing/hiding whatever was last true. This mirrors the same dispatch `webform_ranking.dragdrop.js`'s own `sync()` already performs for its hidden inputs, for the identical reason.

## Alternatives Considered

- **Disable a taken rank's radio in every other row (the original approach):** rejected — creates a permanent lockout once the matrix is fully ranked, since swapping two items' ranks needs two simultaneous mutually-blocking changes.
- **Block the new selection instead of stealing (reject the click, show an error):** not pursued — worse UX than silently reassigning; the respondent's intent ("I want Pizza ranked 1st") is unambiguous even when another item currently holds that rank.
- **Skip the manual `dispatchEvent()` on the bumped radio:** tried implicitly by the original reassignment fix, then found insufficient — a real bug where `#states` conditions targeting a bumped item's rank silently went stale, fixed by adding the explicit dispatch.

## Consequences & Trade-offs

### Positive

- The matrix can be freely rearranged at any fill level, including when every rank is already assigned — no dead-end lockout state.
- `#states` conditions targeting a specific item's rank stay correctly reactive even when that item's rank changes as a side effect of another row's selection, not just from the respondent's own direct click.

### Negative / Caveats

- Every radio being permanently enabled/clickable means the "taken" hint is purely cosmetic (a CSS class) — any future change that tries to make a taken rank genuinely non-interactive would reintroduce the original lockout bug.
- The manual `dispatchEvent()` on a programmatically-bumped input is easy to overlook in a future edit to this handler — omitting it wouldn't fail any test unless a `#states`-watching-a-bumped-rank scenario is specifically exercised.

## Related Code & Docs

- **Files:** `js/webform_ranking.matrix.js` (`initMatrix()`, `markTakenRanks()`)
- **GitHub Issues:** the original rank-exclusivity-lockout fix and its immediate #states-notification follow-up (see git history around `js/webform_ranking.matrix.js` circa 2026-08-21)
