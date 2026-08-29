# ADR-0005: Per-item condition lookup precomputed via drupalSettings, preferring live submitted values

- **Status:** Accepted
- **Date:** 2026-08-23
- **Component/Subsystem:** `WebformRanking::form()` (the `conditionsByItemValue` drupalSettings payload), `js/webform_ranking.items_admin.js`

## Context & Problem Statement

The per-item condition-rows builder (`items_admin.js`) needs to know, for each item's dialog, whether a saved condition already exists and how to decompose it into the picker's row shape. Rows aren't server-rendered per-row: `#webform_multiple`'s `#element` is a single shared template deep-copied per row, and only `#default_value` is known to vary per row through its own generic population mechanism (`setConfigurationFormDefaultValueRecursive()`) — a per-row `#attributes` value baked into the shared template would be identical across every row, unusable for this.

Separately, `WebformUiElementFormBase` caches the *saved* entity's item states in `$form_state->get('element_properties')`, unchanged across `#webform_multiple`'s own AJAX rebuilds (e.g. "Add"/"Remove" elsewhere in the items table). Relying on that snapshot alone meant an in-progress, not-yet-submitted condition edit on one item was silently discarded and reverted to whatever was last saved the moment an admin triggered *any* AJAX rebuild elsewhere on the form (GitHub issue #79) — confirmed via a live DDEV/scripted-Playwright investigation, not guessed: `$form_state->getValues()` was already fully populated with the live submission during that exact kind of AJAX rebuild.

## Decision

A single, item-value-keyed lookup table (`conditionsByItemValue`) is precomputed server-side in `form()` and attached via `drupalSettings`, keyed by each item's own `value` (the stable identity used throughout this codebase — labels/order can change, but `value` cannot once submissions exist). The JS reads the *current* row's own Value field content and looks itself up — no per-row template trickery needed; a brand-new, not-yet-saved row simply has no entry, correctly starting the dialog with one empty condition row.

The lookup's source data prefers `$form_state->getValue(['items', 'items'])` — the form's own live submitted item values, confirmed already fully populated during a `#webform_multiple` AJAX rebuild — over `$element_properties['items']` (the stale saved-entity snapshot), falling back to the snapshot only when no live value exists yet (a genuine first page load, where `$form_state->getValue()` is empty).

Decoding a live value is wrapped in try/catch: unlike the saved-entity snapshot, a live value may be genuinely invalid YAML mid-edit (hand-typed broken text, or a duplicate-selector-under-AND shape the visual builder's own client-side check normally prevents but nothing stops a hand-edited value from producing). A decode failure here falls back to the raw YAML view for that one item — matching `WebformCodeMirror`'s own `validateYaml()` behavior at actual save time — rather than fataling the whole AJAX rebuild over one item's temporarily-invalid text.

## Alternatives Considered

- **Per-row `#default_value`/`#attributes` on the shared `#element` template:** not viable — `#webform_multiple`'s single shared template, deep-copied per row, means only `#default_value` varies per row through its own mechanism; a baked-in `#attributes` value would be identical across every row.
- **Always trusting `$element_properties['items']` (the saved snapshot):** rejected — silently discarded an admin's unsaved condition-dialog edit on any unrelated AJAX rebuild elsewhere in the items table (GitHub issue #79).
- **Letting a live-value decode failure propagate uncaught:** rejected — would fatal the entire AJAX rebuild over one item's temporarily-invalid hand-typed text, rather than degrading gracefully for just that one item.

## Consequences & Trade-offs

### Positive

- One lookup table, one source of truth for "does this item have a decomposable saved condition" — no per-row template complexity to maintain.
- An admin's in-progress condition-dialog edit on one item now survives an unrelated AJAX rebuild triggered elsewhere in the same items table.

### Negative / Caveats

- The live-value-preference logic is a necessary, non-obvious nuance: a future refactor that "simplifies" this back to always reading the saved-entity snapshot would silently reintroduce the #79 data-loss bug, with no test failure unless the specific AJAX-rebuild regression test is run.
- The try/catch around live-value decoding means a genuinely broken hand-typed condition is silently treated the same as "nothing to decompose" in this lookup specifically — not surfaced as an error here (surfaced instead, separately, at actual form submission via `WebformCodeMirror`'s own validation).

## Related Code & Docs

- **Files:** `src/Plugin/WebformElement/WebformRanking.php` (`form()`), `js/webform_ranking.items_admin.js`
- **GitHub Issues:** #65 (original lookup table), #79 (live-values preference fix)
