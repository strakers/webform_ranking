# ADR-0026: Live-toggle a matrix element's required-radio attribute via MutationObserver, not `state:visible`

- **Status:** Accepted
- **Date:** 2026-09-08
- **Component/Subsystem:** `js/webform_ranking.matrix.js` (`initMatrix()`)

## Context & Problem Statement

GitHub issue #142: a production report of a multi-step webform with two `webform_ranking` elements (matrix style, `#required_all` on — the default). The second element's own top-level "only show this element when…" condition (the standard Webform "Conditional logic" tab, distinct from a *row's own* per-item condition) is triggered by a rank radio belonging to the first element. When the second element ends up hidden and the respondent tries to advance the wizard, the browser's native HTML5 constraint validation blocks the step from advancing and logs one console warning per rank radio: `An invalid form control with name='...[matrix][...]' is not focusable.` This is a genuine block, not cosmetic — per spec, a hidden `required` control still counts as invalid even though it can't be focused to show the respondent.

`buildMatrix()` unconditionally writes a native `required` attribute on every rank/N/A radio cell when `#required_all` is on. It already withholds this for a row whose *own* per-item condition applies (`$suppress_static_required`, ADR-0018), but has no equivalent awareness of the *element's* own top-level `#states` — that gap is what this issue exposes.

Normally this is harmless: Drupal core's `webform.states.js` has a generic mechanism (`$document.on('state:visible', ...)` → `backupValueAndRequired()`/`clearValueAndRequired()`) that clears `required` on every `:input` inside any webform element whenever its wrapper's own `state:visible` fires false. Confirmed via live reproduction (a throwaway Playwright script against the reporter's real webform, in a sandboxed copy of their site — the same investigative method issue #123 used) that this generic mechanism correctly handles the plain "element hidden by an ordinary field" case, across Chromium, Firefox, and WebKit.

But: when the element's visibility trigger selector points at an `:input` that lives inside a *different* ranking element's own per-item-conditionally-hidden row (this module's own row-level `data-drupal-states` mechanism, ADR-0012) — e.g. item "ab" in ranking element A has its own `invisible` condition, and ranking element B's whole visibility depends on item "ab" being ranked 1st — `state:visible` **never fires at all** on ranking element B's wrapper. Confirmed directly via live instrumentation: bound a real jQuery listener to the wrapper and logged every firing across multiple reproduction attempts — empty specifically in this "chained trigger" shape, present in every other scenario tested (fresh page load, a live reveal-then-hide "steal" interaction, three browser engines). Removing the per-item condition on item "ab" — leaving only the plain element-level condition — made the bug disappear immediately, confirmed live by the reporter, isolating the chained-trigger shape as the actual trigger. The exact internal mechanism inside Drupal core producing this gap was not further reverse-engineered — the empirical fact (this event doesn't fire in this specific, reproducible shape) is what this decision is based on, not a theory about *why*.

**A second, independently necessary ingredient, found only after the config shape above still failed to reproduce in an isolated test:** the chained-trigger config alone reproduces the missing `state:visible` event, but a *single-page* webform submitted via the plain Submit button did not reproduce the actual reported symptom (the wizard step failing to advance) in extensive isolated testing — including with the exact chained-trigger shape, matching `#required`/`#required_all`/`#require_first_place`/`#allow_na` properties, and even the reporter's exact `drupal/webform` version (`6.3.0-beta8`) pinned locally. Only reproduced once the config was rebuilt as a genuine multi-page **wizard**, with the ranking elements on one page and a "Next" click advancing past them to a further page — confirmed by the reporter directly on their own environment, then independently reproduced in an isolated `WebDriverTestBase` test once restructured the same way. The exact reason a wizard's AJAX page-to-page transition matters here (versus a flat single-page submit) was not further isolated — as with the `state:visible` gap above, this is a confirmed empirical requirement for reproducing the *reported* symptom, not a theorized mechanism. Practically: the fix and its regression test only needed the config shape to correctly observe `required` never clearing; the wizard requirement matters for demonstrating the actual browser-block symptom, not for the fix's own correctness (a client-side attribute never being cleared is wrong regardless of whether a flat submit happens to tolerate it).

## Decision

Don't depend on `state:visible` at all for this. `initMatrix()` now:

1. Captures every radio that carries `required` at initial render, once, before any toggling:
   ```js
   var requiredInputs = Array.prototype.slice.call(table.querySelectorAll('input[required]'));
   ```
   A row suppressed by its own per-item condition (ADR-0018) is structurally never in this list — `buildMatrix()` never wrote `required` on it in the first place, so there's no interaction with that unmodified, existing mechanism.
2. If any exist, locates the element's own wrapper the same way `webform_ranking.dragdrop.js`'s #123 fix already does (`table.closest('.js-webform-ranking')`), and attaches a `MutationObserver` on it (`attributes: true`) rather than a `state:visible` listener. On every mutation, re-derives actual visibility directly (`wrapper.offsetParent !== null`) and toggles `.required` on every captured input to match, only when it actually changed.

`offsetParent` as the ground truth (rather than any Drupal-dispatched event) is the same technique this file's own per-row seeding already trusts over events, for the same underlying reason — see ADR-0012/ADR-0023's own precedent of not fully trusting `state:visible`'s timing/firing guarantees. A `MutationObserver` catches *whatever* mechanism actually changes the wrapper's rendered visibility (a class toggle, an inline style, anything), so it doesn't matter which specific attribute Drupal core or Webform happens to mutate, or whether it fires its own custom event alongside doing so.

This single mechanism covers both the normal case (already worked today via core's own `state:visible`-driven clearing — this observer is redundant-but-harmless there) and the chained-trigger edge case (where core's own mechanism silently no-ops) with no second, event-based code path to keep in sync.

## Alternatives Considered

- **Mirror `required`/`optional` into `#states` on the bare radio cells** (the way Webform core's `WebformCompositeBase` does for its own sub-elements, via `WebformElementHelper::getRequiredFromVisibleStates()`): rejected outright, regardless of this scenario. ADR-0018 already found this crashes `WebformSubmissionConditionsValidator::validateFormElement()` with an uncaught `PluginNotFoundException` for any bare `#type => radio`/`container` sub-element carrying a `required`/`optional` `#states` key — that crash is about the sub-element's own type having no registered Webform element plugin, not about which condition is being mirrored, so it would fire identically whether the mirrored condition came from an item's own state or the element's own top-level state.
- **Bind a `state:visible` listener on the wrapper** (the originally-considered, simpler fix, matching `webform_ranking.dragdrop.js`'s existing #123 pattern): rejected once live instrumentation proved this exact event doesn't fire in the chained-trigger scenario that's the actual subject of this issue — a fix bound to that event would have silently not fixed the reported bug at all.
- **Permanently withhold `required` whenever the element has its own top-level `#states`**, matching ADR-0018's per-item approach exactly: rejected — that trade-off (never getting the browser-native "you must fill this in" nag, even while genuinely visible) was already accepted once for the narrower per-item case; extending it to the much more common element-level case would degrade UX for the vast majority of element-level conditions that already work correctly via core's own generic mechanism, to fix a gap that only affects one specific chained shape.

## Consequences & Trade-offs

### Positive

- Fixes the actual reported blocking bug without touching the crash-prone `#states`-mirroring route, and without degrading the native browser-validation UX for the common case (which already worked, and keeps working, via core's own mechanism).
- No PHP change needed — `buildMatrix()` keeps writing `required` unconditionally exactly as before; this is purely a client-side correction of what already gets rendered.
- Robust against *why* `state:visible` doesn't fire here specifically — the fix doesn't depend on understanding or trusting that event at all, so it isn't vulnerable to some other, not-yet-found trigger shape with the same gap.

### Negative / Caveats

- A `MutationObserver` per matrix instance with `attributes: true` fires on *any* attribute mutation on the wrapper, not just visibility-relevant ones (e.g. `data-once` markers from other behaviors) — mitigated by only actually toggling `required` when the derived `offsetParent !== null` boolean has genuinely changed since last observed, so extraneous mutations are cheap no-ops.
- The exact Drupal-core mechanism causing `state:visible` to not fire for a chained trigger was not reverse-engineered — if a future Webform core release changes this behavior, this fix remains correct regardless (it never depended on the event), but the underlying "why" documented here is empirical, not derived from reading core's source for this specific gap.

## Related Code & Docs

- **Files:** `js/webform_ranking.matrix.js` (`initMatrix()`, `initElementLevelRequiredToggle()`, `applyRequired()`)
- **Tests:** `tests/src/FunctionalJavascript/WebformRankingChainedVisibilityRequiredJavaScriptTest.php`
- **Related:** ADR-0018 (the `#states`-mirroring crash this fix deliberately avoids re-triggering), ADR-0012/ADR-0023 (the "don't fully trust `state:visible`'s timing, trust `offsetParent`" precedent this fix extends to a new case), ADR-0020 (drag/drop's own `state:visible`-dependent wrapper listener — a related, not-yet-confirmed risk tracked separately, see below)
- **GitHub Issues:** #142 (this fix); #144 (drag/drop may have an analogous, unconfirmed gap in its own `state:visible`-dependent re-sync — deliberately out of scope here)
