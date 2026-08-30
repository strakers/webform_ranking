# ADR-0003: Per-item conditional-inclusion field: raw #states YAML via webform_codemirror, in a dialog

- **Status:** Accepted
- **Date:** 2026-07-09
- **Component/Subsystem:** `WebformRanking::form()` (the `items['states']` sub-field), `js/webform_ranking.items_admin.js`

## Context & Problem Statement

Each ranking item needed an optional `#states` condition governing whether it's included at all. The first attempt nested Webform's own `webform_element_states` widget — the real "Conditional logic" builder — directly inside a `#webform_multiple` row. That crashed in production: a `TypeError` inside `WebformCodeMirror::validateWebformCodeMirror()`, where an array reached a YAML validator expecting a string (GitHub issue #13).

Separately, once the field worked as a raw YAML textarea, having it inline in every row — even collapsed behind a checkbox — was still real, permanent visual clutter once more than a couple of items had a condition configured (GitHub issue #4).

## Decision

The `states` sub-field is a plain `webform_codemirror` (YAML mode) textarea, not the `webform_element_states` widget — sidestepping the crash entirely rather than debugging the nested-widget interaction further. It's presented inside a per-item dialog (triggered by a "Conditions" button next to each row) rather than inline, closing the visual-clutter gap from issue #4; a dialog also sidesteps the "which row does this belong to" scoping problem an even earlier inline-checkbox design had, since the trigger button is inserted immediately next to its own item's wrapper rather than matched up via a separate DOM search.

Issue #65 later layered a visual condition-rows builder (`items_admin.js`) on top of this *same* textarea, entirely client-side — no changes to this field definition, `prepare()`, `buildMatrix()`/`buildDragDrop()`, `WebformRankingVisibilityResolver`, or `validateConfigurationForm()` were needed for that. The visual builder reads/writes the textarea's YAML text directly; whatever's in it at submit time is what's saved, exactly as when hand-typed.

Two implementation details are load-bearing, not incidental:

- **`'#decode_value' => TRUE`** makes `WebformCodeMirror::validateWebformCodeMirror()` (registered via `processWebformCodeMirror()`) decode the submitted YAML string into a real array before it reaches `$form_state->getValue('items')`. Without it, that method's auto-decode branch only fires when `#default_value` is already an array — never true here, since `#webform_multiple` populates each row's default straight from stored config. Confirmed via a real bug: omitting it left `$item['states']` a raw YAML *string* all the way through `validateConfigurationForm()` into saved config and into `buildMatrix()`/`buildDragDrop()`'s `#states` assignment — Drupal's `FormHelper::processStates()` JSON-encodes a string exactly as happily as an array, so no error surfaced anywhere; the condition just silently never matched (`states.js` can't parse a JSON-encoded string as a conditions object). `prepare()` also has a matching read-side self-heal for config saved before this existed, decoding a leftover string on the way in.
- **The field's exact `#type`/`#mode`/`#wrapper_attributes`/position are unchanged** from the first working version. `items_admin.js`'s dialog-relocation logic (and its "skip a wrapper nested inside another wrapper" duplicate-guard) depends on this exact DOM depth. An earlier attempt at issue #65 (closed PR #64) added a new wrapping container here and, as a side effect, broke the trigger button's own markup.

## Alternatives Considered

- **`webform_element_states` nested directly inside the `#webform_multiple` row:** rejected — crashes (`TypeError` in `WebformCodeMirror::validateWebformCodeMirror()`, GitHub issue #13). Not pursued further once a working alternative (plain YAML textarea) was in hand.
- **Inline per-row YAML field, even collapsed behind a checkbox:** rejected — real, permanent visual clutter once several items had a condition configured (GitHub issue #4); the earlier checkbox-toggle version also had its own row-scoping bug (a structure-agnostic DOM walk-up matched the wrong row against `#webform_multiple`'s real markup).
- **A new wrapping container around the field, to relocate it into the dialog:** tried and rejected — broke the trigger button's markup as a side effect (closed PR #64); the working version instead relocates the field's *existing* server-rendered markup as-is, restructuring nothing.

## Consequences & Trade-offs

### Positive

- No core patch or `webform_element_states` workaround needed — a plain YAML field sidesteps the crash entirely.
- The visual builder (issue #65) could be added later as a pure client-side layer over this same field, with zero changes to any PHP beyond attaching `drupalSettings` — the field definition itself was never touched.

### Negative / Caveats

- `'#decode_value' => TRUE` is a silent footgun if ever removed: there's no error, just conditions that quietly stop matching. Anyone touching this field definition needs to know that before changing it.
- The exact DOM depth (`#type`/`#wrapper_attributes`/position) is now an implicit, undocumented-in-code-structure coupling between this PHP field definition and `items_admin.js`'s relocation logic — not obvious from reading either file in isolation, only from this ADR.

## Related Code & Docs

- **Files:** `src/Plugin/WebformElement/WebformRanking.php` (`form()`'s `items['states']` sub-field), `js/webform_ranking.items_admin.js`
- **GitHub Issues:** #4 (inline clutter), #13 (crash), #65 (dialog + visual builder redo), closed PR #64 (wrapping-container regression)
