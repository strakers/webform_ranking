# ADR-0015: Condition-rows builder mirrors core's real markup/classes, with two confirmed collision workarounds

- **Status:** Accepted
- **Date:** 2026-08-24
- **Component/Subsystem:** `js/webform_ranking.items_admin.js` (`initConditionBuilder()`'s fieldset/table/row construction)

## Context & Problem Statement

The per-item condition-rows picker was built to visually match Webform's own real element-level "Conditional logic" tab exactly — same `<fieldset>`/`<table class="webform-states-table">` structure, same classes — so it inherits the admin theme's existing styling for free rather than looking like a bespoke, differently-styled widget. Matching classes exactly (confirmed by inspecting a live admin form's own rendered markup, `#edit-conditional-logic`) is what actually gets the same visual treatment, since Drupal's admin theme styles fieldsets via those specific classes, not the bare tags.

Reusing the real widget's classes verbatim, however, surfaced two confirmed collisions with behavior that class name also drives elsewhere on the page:

1. **`webform-states-table--state`** is also core's own *unscoped* jQuery selector for `toggleRequiredCheckbox()` in `webform.element.states.js`, which force-checks/disables this element's entirely unrelated top-level "Required" property whenever *any* matching `<select>` anywhere on the page (not just this dialog) is set to Required/Optional.
2. **`webform-states-table--condition`** placed on both the row *and* the inner cell wrapping trigger/value (matching the real widget's own server-rendered markup, `WebformElementStates::buildConditionRow()`) caused a genuine `TypeError: this.source is not a function` from jQuery UI's autocomplete widget — the cell-level match's empty-selector `.find()` still re-ran `.autocomplete({minLength: 0})` on the same value input a second time, corrupting the widget instance the row-level match had already correctly initialized. Caught by a live browser test, not guessed.

## Decision

The visual `.webform-states-table` table-level class is shared with the real widget (for styling), but the two colliding classes are handled differently:

- The state row uses a module-scoped class (`webform-ranking-item-condition-state-row`/`-cell`) instead of `webform-states-table--state`, with the equivalent cosmetic rules copied into this module's own CSS (`webform_ranking.items_admin.css`) — keeping the identical visual result without the `toggleRequiredCheckbox()` collision.
- `webform-states-table--condition` lives on the condition `<tr>` **only**, never also on the inner trigger/value cell — deviating from the real widget's own markup specifically to avoid the double-autocomplete-init bug. The Element dropdown is still correctly located as a descendant from the row, since it's a sibling `<td>` of the trigger/value cell, not nested inside it.

## Alternatives Considered

- **Reuse `webform-states-table--state` verbatim, matching the real widget exactly:** rejected — confirmed to force-check/disable this element's own unrelated "Required" checkbox via core's unscoped selector.
- **Reuse `webform-states-table--condition` on both the row and inner cell, matching the real widget exactly:** rejected — confirmed to corrupt the autocomplete widget instance via a real `TypeError`, caught by a live browser test.
- **Build entirely custom markup/classes instead of mirroring the real widget at all:** not pursued — would forfeit the admin theme's existing styling for free, requiring a parallel stylesheet to achieve visual parity with zero benefit over targeted, documented deviations.

## Consequences & Trade-offs

### Positive

- The condition-rows picker looks identical to Webform's own real builder without a bespoke stylesheet, while avoiding both confirmed collisions with core's own JS behavior.
- Both deviations are narrow and specific (one class renamed, one class relocated) rather than abandoning markup parity altogether.

### Negative / Caveats

- A future edit that "helpfully" restores class parity with the real widget (e.g. moving `webform-states-table--condition` back onto the inner cell, "to match core more closely") would silently reintroduce the autocomplete-corruption bug — there's no automated check specifically guarding this beyond the existing regression test exercising autocomplete wiring.
- The module-scoped state-row CSS must be kept in sync by hand with whatever visual treatment core's own `webform-states-table--state` styling does, since it's a copy, not a shared source.

## Related Code & Docs

- **Files:** `js/webform_ranking.items_admin.js` (`initConditionBuilder()`, `createConditionRow()`), `css/webform_ranking.items_admin.css`
