# Redo #13: faithful visual conditions builder for per-item states

## Context

The first attempt at #13 (branch `feature/visual-conditions-builder-per-item-states`,
PR #64) replaced the raw YAML field with a **custom, simplified** 4-field
picker (mode/element/trigger/value dropdowns). On review this went off the
rails in two ways:

1. **The popup UI didn't match what was intended.** The real ask was to
   *simulate* the element-level "Conditional logic" tab — the UI admins
   already know — not build a smaller, different-looking picker. YAML is
   the actual blocker for most admins; a UI that still looks unfamiliar
   doesn't fully solve that.
2. **The trigger button was unintentionally altered.** Root-caused: the
   first attempt introduced a new `condition_group` wrapper container
   around everything, moving the `webform-ranking-item-states-wrapper`
   class (which `items_admin.js` uses to find/relocate content into the
   dialog) up one DOM level from where it always lived — an incidental
   structural change with no functional reason behind it.

This plan starts over on a fresh branch off `dev`, with the scope
tightened per direction from review:

- **Multi-condition support** (add/remove condition rows, All/Any/One
  combining), matching the real element-level builder's own capability —
  not a cut-down single-condition version.
- **Faithful rebuild**, confirmed scoped entirely to the dialog's
  contents. The trigger button, the dialog open/close mechanics, and
  the rest of the admin items table are explicitly out of scope for
  this change — only what renders *inside* the popup changes.
- **The visual builder writes straight back into the existing `states`
  YAML field — no new field.** Confirmed: `#decode_value => TRUE` simply
  runs whatever's submitted through `Yaml::decode()` with no shape
  restriction, so the `states` key already fully supports multi-condition,
  AND/OR/XOR-shaped `#states` today — anything a hand-written YAML block
  could express, it already accepts. There's no PHP-side capability gap
  to address; this is purely a UI problem.

## Why this design avoids both problems from the first attempt

**On the trigger button:** the `'states'` field's own structure —
`#type`, `#mode`, `#decode_value`, `#wrapper_attributes` (the class
`items_admin.js` uses to find/relocate content into the dialog),
position in `#element` — is **completely unchanged** from the current
`dev` baseline. No new sibling field, no new wrapper container, nothing
added to `#element` at all. The only PHP addition is one more HTML
attribute (`data-*`, via `#attributes`, not `#wrapper_attributes`) on
the *same* textarea — which affects the `<textarea>` tag itself, not
the DOM structure/depth around it.

**On UI fidelity:** rather than re-nest Webform's actual
`webform_element_states` composite inside `#webform_multiple` (confirmed
in the original #13 investigation to be what crashed in production
before), or stand up a single retargeted-via-AJAX instance of it (real
fidelity, but materially more complex/risky), the plan hand-builds the
same visual/interaction pattern client-side, reusing Webform's own CSS
classes and JS for the parts that are safe to reuse (value autocomplete,
styling). The visual builder's *only* job is to keep the same YAML
textarea's text in sync with whatever rows are on screen — there's no
new parsing/composition logic needed server-side at all, since the
textarea's existing submit-time decode (`WebformCodeMirror::validateWebformCodeMirror()`,
already wired via `#decode_value`) handles it exactly as it does for
hand-typed YAML today.

## Design

### PHP — one field, no new keys, no new submit-time logic

`src/Plugin/WebformElement/WebformRanking.php`, the `items`
`#webform_multiple` field's `#element`:

- `'states'`: **unchanged** shape/keys from the current `dev` baseline,
  with one addition to `#attributes` (not `#wrapper_attributes`): a
  `data-condition-rows` attribute carrying a JSON-encoded decomposition
  of the item's *current* `#states` array, computed by a new
  `decomposeItemStatesToConditions()` (mirrors the conventions in
  `\Drupal\webform\Element\WebformElementStates::getFormApiStatesCondition()`/
  `convertFormApiStatesToStatesArray()` for reading both AND
  (associative) and OR/XOR (indexed, `'or'`/`'xor'` operator strings)
  shapes). Returns `null`/omitted for anything genuinely too complex to
  represent (multiple states, unrecognized triggers, malformed
  structure) — JS falls back to showing the raw YAML view by default in
  that case, mirroring the real widget's own
  `isDefaultValueCustomizedFormApiStates()` fallback.
- `$webform->getElementsSelectorOptions()` (element list) and
  `\Drupal\webform\Element\WebformElementStates::getTriggerOptions()`
  (comparison operators) attached once via `#attached['drupalSettings']`
  on the `items` field — JS needs these option lists to populate
  dropdowns when building rows client-side.
- **`validateConfigurationForm()`: no changes.** The visual builder
  writes YAML text into the same textarea the user would otherwise type
  into by hand; whatever's in it at submit time flows through the
  existing, unmodified decode path exactly as today.
- No `composeItemStatesFromConditions()` — not needed. The YAML
  serialization happens client-side (see below); PHP never needs to
  convert visual-builder state into `#states` itself.

### JS — all the new complexity lives here, scoped to the dialog

`js/webform_ranking.items_admin.js` (extended, not replaced):

- On dialog open: read the textarea's `data-condition-rows` attribute.
  If present, render condition rows from it (visual mode). If absent,
  default to showing the YAML textarea directly ("Edit source" view),
  with a short note — same idea as the real widget's own warning
  message for a customized condition.
- Row markup reuses the real builder's own CSS classes
  (`webform-states-table--condition` / `--selector` / `--trigger` /
  `--value`) so `webform/webform.element.states` (already attached
  page-wide via the element-level "Conditional logic" tab) wires up
  value-autocomplete identically, with no new JS of our own for that
  part — `Drupal.attachBehaviors()` called on each newly-inserted row so
  `once()`-based behaviors pick it up.
- "Add condition" (client-side row clone, no AJAX) / per-row "Remove"
  (hidden once only one row remains).
- Combining operator (All/Any/One) shown once 2+ rows exist, same
  wording as the real widget ("if [operator] of the following is
  met:").
- Per-row trigger-driven value-field visibility is this module's own
  small client-side toggle (empty/filled/checked/unchecked hide the
  value input; pattern/between show a short format hint) — the real
  widget does this via server-rendered `#states` bound to a specific row
  index, which doesn't apply to client-cloned rows.
- Mode select (Include/Hide → visible/invisible) stays a single
  dropdown, not a repeatable list — only `visible`/`invisible` are
  meaningful for item inclusion (already the only states
  `WebformRankingVisibilityResolver` acts on), so there's no "Add
  another state" affordance to replicate.
- **New: a small, purpose-built YAML *emitter*** (write-direction
  only — no parser needed, see below) that serializes the current rows
  into `#states`-shaped YAML text on every add/remove/edit, writing it
  directly into the YAML textarea (through CodeMirror's own
  `setValue()`/`save()` API when attached, matching how "Clear
  condition" already writes to this field today). Deliberately
  constrained/low-risk, not a general YAML library:
  - Every string is single-quoted (selectors like
    `:input[name="x"]` contain double quotes, brackets, and colons —
    all irrelevant once single-quoted; the only escape rule needed is
    doubling a literal embedded `'`).
  - Booleans (`empty`/`filled`/`checked`/`unchecked` triggers) emit
    bare `true`.
  - AND (2+ conditions, no explicit operator) emits the associative
    `selector: {trigger: value}` shape; OR/XOR emit the indexed
    `- {selector: {...}}` / `'or'` / `'xor'` shape — mirroring
    `getFormApiStatesCondition()`'s conventions in reverse.
- "Edit source" toggle switches to/from the raw YAML textarea view
  (both operate on the *same* textarea/value — there's only ever one
  underlying field). See "Open implementation-time details" for the
  toggle-back behavior.
- "Clear condition" empties the YAML textarea (as it already does
  today) and resets the visual rows to a single empty row.

### Explicitly unchanged

- `initRow()`'s trigger-button creation (label, class, position) and the
  `Drupal.dialog()` open/close configuration in
  `js/webform_ranking.items_admin.js`.
- `#element`'s key list: `value`, `label`, `states` — identical to the
  `dev` baseline. No new keys, no new wrapping containers anywhere.
- `WebformRankingVisibilityResolver`, `buildMatrix()`, `buildDragDrop()`,
  `prepare()`, `validateConfigurationForm()` — untouched.

## Test plan

- PHP (Kernel or Unit, matching this module's existing convention):
  `decomposeItemStatesToConditions()` round-trips for every trigger
  type, AND-shape, OR-shape, XOR-shape, multiple conditions, and
  confirmation that a genuinely complex/unsupported shape correctly
  returns "not decomposable" (JS falls back to Edit Source) rather than
  corrupting or silently dropping data.
- FunctionalJavascript (`WebformRankingItemsAdminJavaScriptTest`,
  extended): dialog shows the visual builder pre-filled for an existing
  multi-condition item; add/remove condition rows; operator toggle;
  Edit Source view; **saved YAML text matches what the visual builder
  showed** (proves the emitter is correct, not just "looks right");
  save persists correctly end-to-end. Explicit regression check that
  the trigger button's label/position/single-instance behavior is
  byte-identical to the pre-#13 baseline — this exact thing broke last
  time, so it gets its own assertion, not just an implicit pass.
- Live verification against a real webform with a configured item
  condition (the same practice that caught the field-naming collision
  in the first attempt) before considering this done.

## Open implementation-time details (flagged, not blocking approval)

- **Toggling from "Edit source" back to the visual builder mid-edit,
  without closing/reopening the dialog:** re-decomposing arbitrary
  hand-edited YAML text client-side would need a YAML *parser*, not
  just the emitter above. Leaning toward *not* supporting a live
  round-trip — switching back to visual mode restores whatever rows
  were on screen before switching to source, discarding any hand-typed
  YAML edits made in between (closing and reopening the dialog gets a
  fresh, server-computed decomposition of whatever was actually saved).
  Will make sure this reads clearly in the UI copy rather than silently
  losing edits.
- **Pattern/Between fields:** a single value input with a short format
  hint (matching the first attempt's approach), not the real widget's
  separate description blocks per trigger type. Lower fidelity in this
  one spot, but avoids meaningfully more markup/JS for a rarely-used
  trigger type.

## New GitHub issue — drafted (replaces #13, which stays as history)

*(Filed as a brand-new issue. #13 itself is left untouched — a comment
gets added there closing it in favor of the new issue, so the original
investigation stays intact as history/context rather than being
overwritten.)*

> ## Summary
>
> The per-item conditional visibility field requires admins to
> hand-write a raw `#states` YAML block. This works, but is a real UX
> cost compared to the visual conditions builder Webform already
> provides for element-level conditions (the "Conditional logic" tab)
> — YAML is only practical for admins already comfortable with it.
>
> **Supersedes #13.** An earlier attempt there (see closed PR #64)
> built a simplified custom picker instead of replicating the real
> builder's UI, and incidentally altered the "Conditions" trigger
> button's markup as a side effect. This issue retargets the same goal
> at a tighter spec below, addressing both problems directly.
>
> ## Background
>
> An earlier design (`docs/CONTINUATION.md`, Key Design Decision #4)
> nested `webform_element_states` (Webform's real conditions-builder
> element) directly inside the `#webform_multiple` items table and
> crashed in production. Isolating the field in its own dialog (#12/#17)
> never actually changed that underlying nesting — it's a pure
> client-side DOM relocation, confirmed by re-reading the code before
> the first attempt at this issue began.
>
> Also confirmed: the item's `states` field already fully supports
> multi-condition, AND/OR/XOR-shaped `#states` today — `#decode_value`
> just runs the submitted YAML through `Yaml::decode()` with no shape
> restriction. This is purely a UI gap, not a storage/validation one.
>
> ## Goal
>
> Replace the raw YAML field with a visual builder that looks and
> behaves like the real element-level "Conditional logic" tab — same
> dropdowns, same multi-condition support (Add/Remove condition rows,
> All/Any/One combining) — so admins already familiar with that UI feel
> no difference using this one. The builder writes directly into the
> same YAML field that already exists; raw YAML remains available as a
> fallback for anything genuinely too complex to express visually
> (mirroring the real builder's own "Edit source" escape hatch).
>
> ## Approach
>
> - Do **not** nest the real `webform_element_states` composite element
>   inside `#webform_multiple` (confirmed crash risk) or stand up a
>   single AJAX-retargeted instance of it (real fidelity, but
>   significantly more complex/risky).
> - Instead, hand-build the same visual/interaction pattern client-side
>   — reusing Webform's own CSS classes (`webform-states-table--*`) and
>   JS (`webform/webform.element.states`, already loaded page-wide via
>   the element-level tab) for value autocomplete/styling. The builder
>   keeps the *existing* YAML field's text in sync with the visual rows
>   on every change; no new field, no new server-side parsing logic.
> - The existing per-item dialog, its trigger button, and its Done/Clear
>   buttons are explicitly **out of scope** — only the dialog's contents
>   change.
>
> ## Scope
>
> - Multiple conditions per item, with All/Any/One combining — matching
>   the real builder.
> - Only `visible`/`invisible` as the "state" (a single dropdown, not a
>   repeatable list) — the only states this element's own visibility
>   resolver acts on.
> - Raw YAML fallback retained for anything the visual builder can't
>   represent.
