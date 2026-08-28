# ADR-0014: Per-item conditions dialog — non-destructive DOM relocation, form-safe appendTo, condition-rows-DOM-is-the-state

- **Status:** Accepted
- **Date:** 2026-07-12
- **Component/Subsystem:** `js/webform_ranking.items_admin.js` (`initRow()`, `initConditionBuilder()`)

## Context & Problem Statement

Each ranking item's optional condition needed an admin UI that stays out of the way when unused. An earlier, inline per-row checkbox/toggle approach never actually worked (GitHub issue #4): its row-scoping heuristic (a structure-agnostic DOM walk-up, `findRowContainer()`) matched the wrong row against `#webform_multiple`'s real markup. Redesigning to a per-item dialog (GitHub issue #65 later layered a visual condition-rows builder on top of the same textarea, not a replacement field — see ADR-0003 for that field's own PHP-side design) introduced two further, non-obvious implementation constraints:

1. A first attempt at the dialog (closed PR #64) added a new wrapping container around the server-rendered YAML field, which incidentally broke the trigger button's own markup as a side effect.
2. jQuery UI's dialog widget, by default, appends its wrapper to `<body>`. If the wrapped element's own form isn't itself a descendant of `<body>` in a way that keeps it inside `<form>` (typical for an admin page's layout), moving the field's wrapper there would silently drop it out of the submitted form entirely.

## Decision

**Non-destructive DOM relocation:** `initConditionBuilder()` only ever inserts *new* sibling content inside the wrapper this file already finds — it never restructures what PHP rendered. The server-rendered YAML field's own markup (label, textarea, description) is moved as a group into a new container via `while (wrapper.firstChild) { ... }`, preserving its exact internal structure rather than rebuilding it.

**Form-safe dialog placement:** `appendTo` is explicitly set to the closest `<form>` in `Drupal.dialog()`'s options, guaranteeing the field stays a form descendant regardless of dialog visual positioning (dialogs are CSS-positioned as an overlay, so this doesn't affect where the dialog appears on screen). Verified via a real submission test (`WebformRankingItemsAdminJavaScriptTest`): reopening the saved element's config form after using the dialog shows the condition persisted.

**Condition-rows DOM *is* the state:** there's no separate JS data object mirroring the builder's rows. Toggling to the raw YAML view never destroys the rows, only hides them, so toggling back shows exactly what was there before — a single source of truth (the DOM itself) rather than a data model kept in sync with it.

**States API avoided for the trigger button itself:** for the same underlying reason `webform_element_states` itself was avoided for the field (see ADR-0003) — an earlier conditional-UI attempt using `webform_element_states` nested inside a `#webform_multiple` row crashed in production, and leaning on another not-fully-verified Drupal-internals mechanism inside the same nested-widget context was avoided on the trigger button too. The condition-rows *builder* itself does reuse Webform's own `webform/webform.element.states` library, but only for value autocomplete — that library is pure DOM-class-based JS with no comparable nesting risk.

## Alternatives Considered

- **Inline per-row checkbox/toggle (the original approach):** rejected — its row-scoping heuristic broke against `#webform_multiple`'s real markup (GitHub issue #4).
- **A new wrapping container around the field for the dialog** (the first #65 attempt, closed PR #64): rejected — broke the trigger button's own markup as a side effect.
- **Default jQuery UI dialog behavior (append to `<body>`):** rejected — silently drops the field out of the submitted form for a typical admin page layout.
- **A JS data object mirroring the builder's rows, kept in sync with the DOM:** not pursued — the DOM itself is authoritative and simpler to reason about; no sync logic needed between two representations of the same state.

## Consequences & Trade-offs

### Positive

- The trigger button + dialog can be added without ever risking the field's own server-rendered structure, regardless of what admin theme or Webform version renders it.
- The field reliably submits as part of the real form, verified by an actual submission test, not just visual inspection.

### Negative / Caveats

- "Never restructure what PHP rendered" is an implicit constraint any future change to this file's DOM manipulation must keep respecting — it isn't enforced by any test beyond the general suite passing, only by this documented convention.
- The condition-rows-DOM-is-the-state design means hand-edited YAML typed while in "Edit source" view is not re-parsed back into rows automatically — see ADR-0017 for how that specific consequence is handled.

## Related Code & Docs

- **Files:** `js/webform_ranking.items_admin.js` (`initRow()`, `initConditionBuilder()`)
- **GitHub Issues:** #4 (checkbox approach failure), #13 (webform_element_states crash), #65 (dialog + visual builder), closed PR #64 (wrapping-container regression)
