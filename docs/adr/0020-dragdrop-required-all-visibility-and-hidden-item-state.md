# ADR-0020: Drag/drop's #required_all UI relevance and hidden-item state handling

- **Status:** Accepted
- **Date:** 2026-09-01
- **Component/Subsystem:** `src/Plugin/WebformElement/WebformRanking.php`, `js/webform_ranking.dragdrop.js`

## Context & Problem Statement

Two related but distinct drag/drop problems, both reported in GitHub #108:

1. The "Require every visible item to be ranked or marked N/A"
   (`#required_all`) admin checkbox is shown for both display styles, but
   it's a structural no-op for drag/drop: every drag/drop item always
   lands in either the ranked order or the N/A list — there's no
   "unaccounted for" state the way an un-clicked matrix radio produces.
   Showing an admin control with no observable effect for the selected
   display style is confusing.
2. `js/webform_ranking.dragdrop.js` had exactly one visibility-reactive
   listener — page-wide, on `document`, calling only `renumber()` (the
   *visible* position-indicator recompute). Nothing reacted to an
   individual item's own conditional visibility. A hidden item's rank/
   N/A state, and critically its per-item rank *echo* input (ADR-0008 —
   a channel that exists specifically so other `#states` conditions or a
   `webform_computed_twig` element's live recompute can watch "is item X
   ranked Nth"), kept whatever value they last had while hidden. A
   downstream element's own condition watching that echo (e.g. "show a
   follow-up question when Banana is ranked 1st") would incorrectly stay
   matched even after Banana itself was hidden — the same class of leak
   CHANGELOG's #41 entry fixed for the server-side live-recompute path,
   here on the client-side `#states`-echo equivalent.

## Decision

**Point 1 — UI-only hide.** `#required_all` gets a `#states` rule
(mirroring `#na_label`'s existing `#allow_na`-gated pattern) hiding it
when `properties[ranking_style]` isn't `matrix`. Deliberately UI-only:
the stored value is never reset or cleared, so a site builder switching
back and forth between drag/drop and matrix gets back whatever was
configured for matrix, even while it was hidden. No change to
`prepare()`'s default-value handling or to what gets validated.

**Point 2 — hidden item state.** `sync()` — the single place that writes
the authoritative `order`/`na` hidden inputs and every rank echo input —
now computes `order`/`na` from only the currently-visible ranked/N/A
items (`rankedItems().filter(isCurrentlyVisible)` /
`naItems().filter(isCurrentlyVisible)`), and explicitly blanks
(`setRankInput(value, '')`) the rank echo for any item that's currently
hidden, regardless of its N/A flag. This treats a hidden item as
**excluded from the dataset entirely**, not as a third "na-like" status:
given `{bananas:1, oranges:2, apples:na}`, hiding bananas produces
`{oranges:1, apples:na}` — bananas drops out, oranges coalesces up to
fill the gap — matching `WebformRankingConverter::matrixToCanonical()`'s
own treatment of a blank rank on the matrix side.

A new per-item `state:visible` listener (bound on each item's own
container element, which is exactly what carries that item's `#states`
— see `src/Element/WebformRanking.php`'s `dragdrop.list.<value>` render
element) replaces the old page-wide one:

- **On hide:** just calls `sync()`. The item's DOM position doesn't need
  to change (it's invisible either way); the filter above does the
  coalescing as a side effect.
- **On reveal:** calls `setItemNa(item, false)`, reusing its existing
  "toggle N/A off" branch to re-insert the item at the end of the
  current ranked stack (or at the front if nothing's ranked yet) —
  filtering alone isn't enough here, since the item would otherwise
  resume wherever it happened to sit in the DOM pre-hide. This also
  unconditionally clears any N/A flag the item held before being hidden,
  matching the issue's own "reappears not N/A'd" behavior.

`setItemNa()` now also syncs the N/A checkbox's own `checked` property,
deferred via `setTimeout(fn, 0)`. Found via manual testing, not
planning: Webform core's own `webform.states.js` backs up every
`:input` inside a conditionally-hidden webform element before hiding it
and restores that *pre-hide* value on reveal, via its own
document-level `state:visible` handler — which, bound on an ancestor,
runs synchronously right after this item-level handler within the same
event dispatch. An un-deferred `checked = false` here was immediately
overwritten back to the pre-hide (`true`) value by that restore.
Deferring to the next tick lets Webform's own restore run first, so
this fix's own "not N/A'd" outcome is the one left standing.

`isCurrentlyVisible()` can no longer be a pure `offsetParent !== null`
check, as it was before this fix. Confirmed directly (a temporary
instrumented handler, since removed): the `state:visible` event fires
*before* states.js's own DOM-hiding effect actually takes effect — a
hide-time `sync()` call, running synchronously inside that same event
handler, observed `offsetParent` still non-null and produced no
change at all. `isCurrentlyVisible()` now consults a `knownVisible`
value-keyed map instead, updated directly and synchronously from each
event's own `e.value` the instant it fires (seeded from `offsetParent`
at init, for the already-settled initial-page-load case) — the same
"don't trust the DOM, trust the event's own boolean" approach
`webform_ranking.matrix.js`'s analogous `visible` map already uses, for
the identical reason.

## Alternatives Considered

- **Mark a hidden item's rank echo as `'na'` instead of blanking it:**
  rejected. `'na'` is a real, respondent-facing choice — using it as a
  stand-in for "hidden" misrepresents state whenever `#allow_na` is off
  entirely (there's no N/A concept to borrow), and doesn't produce the
  coalescing behavior actually wanted.
- **Reveal-time reset only, no hide-time action:** rejected. Everything
  visible-facing (`renumber()`, `moveItem()`'s valid targets) already
  filters by visibility on its own, so a hide-time DOM change would have
  no visible effect — but the rank echo isn't visible-facing, and is
  genuinely read by other `#states` conditions and computed-twig live
  recomputes while the item is hidden. Reveal-only leaves that leak
  open for the item's entire hidden duration, not just until the next
  interaction.
- **`#states`-gate `#required_all` and reset its stored value on style
  switch:** rejected. The issue's own follow-up comment requires the
  stored value survive a round trip through drag/drop and back to
  matrix, so a site builder isn't surprised by a silently-reset setting.

## Consequences & Trade-offs

### Positive

- The admin form no longer shows a control with zero effect on the
  currently-selected display style.
- A hidden drag/drop item's rank is no longer observable by any
  downstream `#states` condition or live computed-twig recompute — the
  client-side value now matches what a fresh page load / server-side
  submission would produce.
- Coalescing falls out of the existing visibility filter for free; no
  separate hide-time repositioning logic was needed.

### Negative / Caveats

- A respondent whose item gets hidden loses its position in the
  submitted order the moment it's hidden, even if only momentarily
  (e.g. a flickering trigger value) — the same trade-off already
  accepted for the matrix side (ADR-0019) and for visibility hides in
  general.
- `sync()`, `renumber()`, and `moveItem()` all share `isCurrentlyVisible()`
  — a reader touching one should know it now depends on the `knownVisible`
  map being kept current by the `state:visible` listener, not purely on
  live DOM measurement.
- The N/A checkbox's `checked` property is corrected on a deferred tick,
  not synchronously — a reader relying on it being current immediately
  after `setItemNa()` returns (e.g. a future synchronous test assertion)
  needs to account for that one-tick delay.

## Related Code & Docs

- **Files:** `src/Plugin/WebformElement/WebformRanking.php`,
  `js/webform_ranking.dragdrop.js`
- **Related:** ADR-0008 (rank echo channel — the data channel this fix
  keeps in sync with visibility), ADR-0012 / ADR-0019 (the matrix side's
  own equivalent hidden-item-state handling)
- **GitHub Issues:** #108

> **Correction (2026-09-03, see ADR-0023, GitHub #123):** the `offsetParent`-based initial seed this ADR describes (point 2's last paragraph) was applied unconditionally to every item, not just items with their own per-item `#states`. `offsetParent` is also `null` when *this element's own top-level* `#states` is what's currently hiding the whole list (cross-page trigger, or any other same-page trigger) — in that case every item's `offsetParent` reads `null` at first attach regardless of any per-item condition, so every item was wrongly seeded `knownVisible = false`, and `sync()` — which decides the actual submitted `order`/`na` values from `isCurrentlyVisible()` — silently excluded every item from the submission, permanently (nothing re-seeds once the element's own visibility later resolves true). `isCurrentlyVisible()` now returns `true` unconditionally for an item with no `data-drupal-states` of its own (nothing could ever have hidden it individually), and the `knownVisible` seeding loop only runs for items that do carry it.
>
> That guard alone wasn't sufficient, found via the new regression test itself: nothing had ever called `sync()` again once only the *element's own* visibility changed — the only existing re-sync trigger is the per-item `state:visible` listener this ADR describes, which never fires for an item with no condition of its own. `initDragdrop()` now also binds a `state:visible` listener on the element's own wrapper (`.js-webform-ranking`, the same element `preRenderWebformRanking()` copies `data-drupal-states` onto), re-calling `sync()` on reveal. That re-call has to be deferred a tick (`setTimeout(sync, 0)`), for the exact same race this ADR's own point 2 already documents for `setItemNa()`'s checkbox sync: Webform core's `webform.states.js` backs up and clears every `:input` inside a conditionally-hidden element on hide and restores that stale backup on reveal, via its own synchronous, earlier-registered `state:visible` handler — an un-deferred `sync()` call would just get immediately overwritten by that restore, same as the un-deferred checkbox fix originally was.
