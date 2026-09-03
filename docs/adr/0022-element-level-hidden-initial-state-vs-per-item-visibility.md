# ADR-0022: Distinguish "this element itself starts hidden" from "this item is individually hidden" in client-side visibility seeding

- **Status:** Accepted
- **Date:** 2026-09-03
- **Component/Subsystem:** `js/webform_ranking.matrix.js` (`initMatrix()`), `js/webform_ranking.dragdrop.js` (`isCurrentlyVisible()`, `initDragdrop()`)

## Context & Problem Statement

GitHub issue #123: a `webform_ranking` element whose own **element-level** `#states` condition (the standard Webform "Conditional logic" tab) hides the whole element on initial page load rendered with every item row permanently missing and rank columns collapsed to just "1st", even once the condition was later satisfied and the element became visible. A second report, folded into the same issue, showed the identical symptom for a same-page trigger shape: a second ranking element whose visibility depends on a first ranking element's own per-item rank value (`:input[name="ranking1[matrix][item]"]`) — no cross-page trigger involved at all.

Extensive investigation (documented in the 2026-09-03 corrections to ADR-0006, ADR-0012, and ADR-0020) ruled out every server-side cause: dozens of Kernel-test and real-HTTP reproduction attempts, across cross-page and same-page triggers, AJAX and non-AJAX wizard pages, Gin theme, and the exact reported `drupal/webform` version, all produced *structurally correct* render output and HTML. The bug turned out to be entirely client-side.

Root cause: both `js/webform_ranking.matrix.js`'s `initMatrix()` and `js/webform_ranking.dragdrop.js`'s `isCurrentlyVisible()`/seeding loop use `offsetParent === null` as a proxy for "is this row/item currently hidden by its own per-item `#states`" — a deliberate, documented technique (ADR-0012, ADR-0020) for recovering a per-item condition's already-applied result, since states.js's own `state:visible` event for that item fires before either behavior's own listener exists to catch it. `offsetParent` is `null` whenever *any ancestor* is `display:none` — not just when the row/item's own condition hid it. When this element's own top-level `#states` is what's currently hiding the whole thing, every row/item's `offsetParent` reads `null` at first attach regardless of any per-item condition, so every row/item was wrongly seeded as individually hidden — permanently, since this seeding runs once (`initMatrix()` via `once()`; the dragdrop seeding loop at `initDragdrop()`) and the only re-sync path is a **per-item** `state:visible` listener, which never fires for an item with no condition of its own.

For drag/drop specifically, this was higher-impact than a display bug: `sync()` — which computes the actual submitted `order`/`na` hidden input values from `isCurrentlyVisible()` — silently excluded every item from the real submission, not just from what's rendered.

## Decision

`offsetParent === null` is only ever meaningful for a row/item that can actually be individually hidden by states.js at all — `buildMatrix()`/`buildDragDrop()` only ever attach `#states` to a cell (matrix) or item container (dragdrop) when that item's own `states` config is non-empty, meaning only such a row/item ever carries a `data-drupal-states` attribute of its own. For a row/item with no condition of its own, the correct visibility is unconditionally `true` — nothing could have hidden it individually, and any `offsetParent === null` reading for it can only be explained by an ancestor being hidden, which is irrelevant to its own state.

Both files now guard their `offsetParent` consultation behind a `hasAttribute('data-drupal-states')` check on the row/item's own element:

- **Matrix** (`initMatrix()`'s seeding loop): only seeds `visible[name] = false` when the row's first radio itself carries `data-drupal-states`.
- **Drag/drop** (`isCurrentlyVisible()`): returns `true` immediately for an item with no `data-drupal-states` of its own, before consulting `knownVisible`/`offsetParent` at all; the `knownVisible` seeding loop in `initDragdrop()` only runs for items that do carry it.

This alone was sufficient for matrix (confirmed via the new `WebformRankingElementLevelHiddenInitStateJavaScriptTest`): `toggleRow()`/`updateRankColumns()` run once, synchronously, during the same seeding pass that now gets the right answer from the start, and never need to run again — the native `hidden` attribute they set is correct from initial attach regardless of when the element itself later becomes visible.

Drag/drop needed one more piece, found via the same new test: nothing had ever called `sync()` again once only the *element's own* visibility changed (the only existing re-sync trigger being the per-item listener, which never fires here). `initDragdrop()` now also binds a `state:visible` listener on the element's own wrapper (`.js-webform-ranking` — the element `preRenderWebformRanking()` copies `data-drupal-states` onto for this element's own top-level `#states`), re-calling `sync()` on reveal. That re-call is deferred a tick (`setTimeout(sync, 0)`), for the same race ADR-0020 already documents for `setItemNa()`'s checkbox sync: Webform core's `webform.states.js` backs up and clears every `:input` inside a conditionally-hidden webform element on hide, then restores that now-stale backup on reveal, via its own synchronous, earlier-registered `state:visible` handler — an un-deferred `sync()` call would just be immediately overwritten by that restore.

## Alternatives Considered

- **Bind an element-level `state:visible` re-sync listener for matrix too, matching drag/drop:** rejected as unnecessary — matrix's row/column visibility is expressed entirely via the native `hidden` attribute on non-`:input` elements (`<tr>`, `<th>`, `<td>`), which Webform core's own backup/restore mechanism (scoped to `:input` elements) never touches. Getting the initial seed right is sufficient; there's nothing to re-sync later. Confirmed empirically — the matrix regression tests pass without it.
- **Re-derive `offsetParent` fresh inside a wrapper-level `state:visible` handler, instead of the `data-drupal-states`-presence guard:** rejected. ADR-0020 already documents that `state:visible` fires *before* states.js's own DOM-hiding effect actually completes, making a synchronous `offsetParent` re-check inside that handler unreliable — the same reason `isCurrentlyVisible()` moved off a pure `offsetParent` check in the first place. The presence guard needs no timing assumption at all: whether an item carries `data-drupal-states` is a static fact of how the element was built, not something that changes as visibility toggles.
- **Track "does this element have its own top-level `#states`" and skip the per-row/item `offsetParent` seeding entirely in that case:** considered, but rejected as strictly worse than the guard actually chosen — it would also suppress the *legitimate* per-item seeding for an item that has its own condition *and* happens to sit inside an element that also has its own top-level condition (both are common together, e.g. this issue's own second report). The `data-drupal-states`-presence guard handles both cases correctly with a single, local check.

## Consequences & Trade-offs

### Positive

- An element-level `#states` condition (cross-page or same-page trigger, any shape) that starts an element hidden no longer permanently corrupts that element's own rows/items once it becomes visible.
- The fix is minimal and local to the existing seeding logic — no new architecture, and ADR-0012/ADR-0020's own documented per-item `state:visible` sync path is unchanged.
- Drag/drop's fix additionally closes a real data-loss bug (items silently dropped from the actual submission), not just a display one.

### Negative / Caveats

- The `data-drupal-states`-presence check assumes buildMatrix()/buildDragDrop() never attach `data-drupal-states` to a row/item for any reason *other than* that item's own per-item `states` config — true today, but a future change adding some other per-item `#states` usage would need to account for this guard's assumption.
- Drag/drop's element-level re-sync depends on the same "declared library dependency, not browser-enforced" attach-ordering assumption ADR-0012/ADR-0020 already accept for the per-item case (states.js's own element-level hide/reveal handling must have already run by the time this new listener's deferred callback fires).

## Related Code & Docs

- **Files:** `js/webform_ranking.matrix.js` (`initMatrix()`), `js/webform_ranking.dragdrop.js` (`isCurrentlyVisible()`, `initDragdrop()`)
- **Tests:** `tests/src/FunctionalJavascript/WebformRankingElementLevelHiddenInitStateJavaScriptTest.php`
- **Related:** ADR-0012 and ADR-0020 (both amended 2026-09-03 with corrections pointing here); ADR-0006 (the closest server-side precedent, for the unrelated but structurally similar cross-page *item*-condition gap this issue was originally mistaken for)
- **GitHub Issues:** #123
