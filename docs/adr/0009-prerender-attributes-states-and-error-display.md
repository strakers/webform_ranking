# ADR-0009: preRenderWebformRanking — routing #attributes/#states to #wrapper_attributes, and always suppressing inline_form_errors

- **Status:** Accepted
- **Date:** 2026-08-21
- **Component/Subsystem:** `WebformRanking::preRenderWebformRanking()`

## Context & Problem Statement

`FormElementBase` — unlike every native form element (e.g. `Radio::preRenderRadio()`) and unlike Webform's own `WebformCompositeBase::preRenderCompositeFormElement()` — sets no validation-failure attributes or inline error text on its own (GitHub issue #47). This class deliberately extends `FormElementBase` directly rather than `WebformCompositeBase`, per the storage-boundary rationale in the class docblock, so it doesn't get that machinery for free and has to replicate the relevant pieces itself.

Two further, non-obvious rendering-pipeline facts shaped how:

1. `RenderElementBase::setAttributes()` is hardcoded to write `#attributes` — but for an element with `#theme_wrappers => ['form_element']` (this one), `#attributes` is never actually rendered anywhere. `FormPreprocess::preprocessFormElement()` reads `#wrapper_attributes` exclusively for the wrapping `<div>` (confirmed directly: `$variables['attributes'] = $element['#wrapper_attributes'];`, with `#attributes` referenced nowhere else in that method except an unrelated `disabled` variable). Webform's own composite elements sidestep this by defaulting to `#theme_wrappers => ['fieldset']` instead (where `#attributes` *does* map onto the `<fieldset>` tag) — deliberately not adopted here, since issue #47 itself flagged that fieldset/legend semantic change as a separate, deferred decision.
2. Core's own `form-element.html.twig` supports an inline `errors` template variable — but `FormPreprocess::preprocessFormElement()` unconditionally sets it to `NULL` for every field on every form, regardless of the `inline_form_errors` module's status (confirmed by reading that method directly; the assignment isn't gated on anything). Relying on that variable would never show anything (GitHub issue #48).
3. Element-level `#states` (the element's own "Conditional logic" tab, distinct from per-item `#states`) silently never worked either: `Renderer::doRender()`'s `FormHelper::processStates()` writes the `data-drupal-states` attribute states.js reads onto `#attributes` — which, per point 1, is never rendered for this element at all. The only visible trace was Webform's own no-JS-fallback `js-webform-states-hidden` class, computed once server-side and then frozen forever with nothing to update it client-side.

## Decision

**Attributes/states routing:** `preRenderWebformRanking()` computes what would normally land on `#attributes` (error class, `aria-invalid`, `data-drupal-states`) and copies it onto `#wrapper_attributes` instead, where `form-element.html.twig` actually reads it. For `#states` specifically: call `FormHelper::processStates($element)` to get its canonical JSON-encoded output (not reimplemented here, so it can't drift from core's own encoding), then copy the result across. `processStates()` still runs again later in `Renderer::doRender()` itself — redundant but harmless, since `#states` is still set and encoding it twice produces the same string both times.

**Inline error text:** the validation-failure error message is injected as an ordinary descendant render item (`ranking_errors`, `#weight => 1000` to sort last regardless of which display style built the rest) rather than relying on the always-`NULL`'d `errors` template variable — bypassing that suppressed path entirely.

**`inline_form_errors` handling:** this element's own `#theme_wrappers => ['form_element']` means the `inline_form_errors` module's `hook_preprocess_form_element()` targets this element too (not just its sub-radios, already suppressed in `buildMatrix()`) — its core-preprocess-suppressed `errors` variable gets restored right back once that module is enabled, duplicating the `ranking_errors` child just added. `#error_no_message` (core's own convention for opting out of that hook) is set unconditionally, always suppressing `inline_form_errors` and keeping this element's own rendering as the single, unconditional code path — see the `*** DELIBERATE DESIGN DECISION ***` note at the call site for the full reasoning and revisit criteria, preserved inline rather than only here.

## Alternatives Considered

- **Adopt `#theme_wrappers => ['fieldset']` like Webform's own composite elements:** rejected — issue #47 itself flagged the fieldset/legend semantic change as a separate, deliberately deferred decision, not something to bundle into this attributes/states fix.
- **Reimplement `#states`'s JSON encoding instead of calling `FormHelper::processStates()`:** rejected — would risk silently drifting from core's own encoding over time; calling the real method guarantees byte-identical output.
- **Detect `inline_form_errors` and defer to its own rendering when active** (the module-aware alternative to always suppressing it): considered and rejected — see the inline `DELIBERATE DESIGN DECISION` marker for the full reasoning (functionally identical markup either way, module detection adds real complexity for no visual gain) and the explicit criteria for revisiting this choice later.

## Consequences & Trade-offs

### Positive

- Validation-failure styling, inline error text, and element-level `#states` all work correctly for an element that gets none of `FormElementBase`'s or `WebformCompositeBase`'s usual machinery for free.
- The `#states` fix reuses core's own encoding function rather than a hand-rolled equivalent, so it can't silently drift from how core itself would encode the same condition.

### Negative / Caveats

- The entire `#attributes`-is-never-rendered-here fact is a non-obvious, `#theme_wrappers`-specific quirk — any future change to this element's `#theme_wrappers` (e.g. finally adopting `fieldset`, per issue #47's deferred follow-up) would need to revisit every place this method writes to `#wrapper_attributes` instead of `#attributes`.
- Always suppressing `inline_form_errors` is a deliberate, flagged-for-future-re-review choice, not a permanent one — see the inline marker's own revisit criteria (a future divergence in either template's error markup).

## Related Code & Docs

- **Files:** `src/Element/WebformRanking.php` (`preRenderWebformRanking()`)
- **GitHub Issues:** #46 (matrix markup, unrelated but same era), #47 (missing failure attributes), #48 (missing inline error text), #57 (element-level #states), #69 (inline_form_errors duplicate suppression)
