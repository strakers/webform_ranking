# ADR-0013: Drag/drop reorder built on Pointer Events, with always-present buttons as the primary interaction

- **Status:** Accepted
- **Date:** 2026-08-21
- **Component/Subsystem:** `js/webform_ranking.dragdrop.js`

## Context & Problem Statement

The drag/drop display style needed a reorder mechanism that behaves consistently across mouse, touch, and keyboard, and is genuinely usable by assistive technology — not just visually functional for a mouse user. Two separate technology choices had to be made: what powers the drag gesture itself, and what the *primary*, always-available interaction is (as opposed to a bolt-on fallback).

## Decision

**Pointer Events (`pointerdown`/`pointermove`/`pointerup`/`pointercancel`), not the native HTML5 Drag and Drop API.** HTML5 DnD behaves inconsistently across browsers and has no touch support at all, working directly against the cross-device consistency this element needs. Pointer Events already unify mouse, touch, and pen into one event model — one implementation, no per-input-type branching.

**Server-rendered move-up/move-down buttons are the primary, fully-equivalent interaction, not a fallback bolted on after the fact.** Pointer-dragging is a convenience layered on top for users who want it; arrow keys (when the item itself has focus) are a shortcut on top of the buttons. All three paths funnel through the same `sync()` function, so drag-reorder and keyboard-reorder can never drift out of sync with each other.

**No true no-JS fallback is attempted.** There's no plain-HTML mechanism to persist a drag reorder short of a full-page round trip per move, and that isn't meaningfully achievable for this display style. This is an accepted, documented gap — sites with a hard no-JS requirement should use the matrix style instead, which degrades to plain radio buttons.

A related, narrower decision: rank *numbering* (the visible "N of M" position indicator, and move-button disabled state) only counts items `states.js` currently shows (`isCurrentlyVisible()`, via `offsetParent !== null`) — "dynamic ranks" that shift as conditional items show/hide, rather than fixed positions regardless of visible count (rejected earlier as confusing). This only affects the display; the actual submitted `order`/`na` values always include every present item, and `validateWebformRanking()` is what authoritatively strips anything not currently visible, server-side.

## Alternatives Considered

- **Native HTML5 Drag and Drop API:** rejected — inconsistent cross-browser behavior and no touch support, directly conflicting with the cross-device requirement.
- **Drag-only reordering with keyboard/buttons as an afterthought fallback:** rejected — a fallback bolted on after the fact tends to be a second-class, easily-neglected path; treating the buttons as the actual primary interaction (with drag and arrow keys layered on top of the *same* underlying `sync()` mechanism) avoids two interaction models silently drifting apart.
- **Attempt a no-JS reorder mechanism (e.g. per-move form submission):** rejected — a full-page round trip per single reorder step is impractical UX; sites needing guaranteed no-JS support have the matrix style available instead.
- **Fixed rank numbering regardless of currently-visible item count:** rejected — confusing when conditional items are in play (a position indicator that doesn't match what's actually rankable).

## Consequences & Trade-offs

### Positive

- One reorder implementation handles mouse, touch, and pen without per-input-type branching.
- Keyboard-only and assistive-technology users get a fully equivalent, non-degraded interaction (the buttons), not a second-tier experience.
- All three interaction paths (buttons, arrow keys, pointer drag) share one `sync()` write path, eliminating any risk of them disagreeing about the current order.

### Negative / Caveats

- This display style is unusable for a no-JS visitor — a known, accepted, and documented limitation, not an oversight. Any site requiring guaranteed no-JS functionality must use the matrix style instead.
- The dynamic-rank display (position indicator counts only currently-visible items) means the number shown to a respondent can shift as unrelated conditional items elsewhere change visibility — an intentional trade-off for clarity, but a behavior a future contributor might mistake for a bug if this rationale isn't visible.

## Related Code & Docs

- **Files:** `js/webform_ranking.dragdrop.js`, `src/Element/WebformRanking.php` (`buildDragDrop()`)
