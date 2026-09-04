# ADR-0024: `default_value` must stay a declared property — suppress only its admin widget

- **Status:** Accepted
- **Date:** 2026-09-03
- **Component/Subsystem:** `src/Plugin/WebformElement/WebformRanking.php`

## Context & Problem Statement

GitHub #129: a `webform_ranking` element's selections reset to unranked
when a respondent navigates to a later wizard page and back
("Previous"), and never appear on the Preview page — confirmed specific
to the ranking element; every other field type on the same page
persists correctly across the same round trip. This affects final
submission data, not just display.

`WebformSubmissionForm::populateElements()` is the **only** place
Webform core copies a submission's previously-saved element data back
onto `$element['#default_value']` before rendering, on every rebuild
(wizard "Previous", draft resume, editing an existing submission). Its
gate:

```php
if ($element_plugin->hasProperty('default_value') || $is_hidden) {
  $element['#default_value'] = $values[$key];
}
```

`hasProperty('default_value')` is `array_key_exists('default_value',
$this->getDefaultProperties())`. Confirmed via `grep` across Webform
core that this is the *only* place anything checks
`hasProperty('default_value')`.

`WebformRanking::defineDefaultProperties()` previously did
`unset($properties['default_value']);` — a deliberate choice, per its
own then-comment, to stop the admin element-config form from offering a
generic "Default value" widget that could accept a scalar/YAML value
incompatible with this element's canonical `{values, na}` shape and
crash `WebformRankingConverter::canonicalToMatrix()`. Side effect,
unintended: `hasProperty('default_value')` now returns `FALSE`
unconditionally, so `populateElements()` never repopulates
`#default_value` for this element on *any* rebuild — reproducing
exactly "resets to unranked" and "blank on Preview" (`WebformRanking::
prepare()` falls to its own empty-default branch when `#default_value`
was never set).

Traced the removal mechanism `unset($properties['default_value'])` was
actually trying to defeat:
`WebformElementBase::buildConfigurationForm()` →
`setConfigurationFormDefaultValueRecursive()`, which checks
`array_key_exists($property_name, $element_properties)` (backed by the
same `getDefaultProperties()`) and only calls
`unset($form[$property_name])` when the property *isn't* declared. One
flag was being asked to satisfy two different concerns —
"is this property declared" (which `populateElements()` also depends
on) and "does the admin form need a widget for it" — and Webform core
gives no way to decouple them via the property-declaration alone.

Confirmed via a live check (Kernel test driving a real
`\Drupal::formBuilder()->submitForm()` "Next" click,
`WebformRankingWizardValuePersistenceTest::
testNextPageClickSavesRankingData()`) that the *save* path was never
broken — a ranking selection reaches `$webform_submission->getData()`
correctly the moment "Next" is clicked, with no validation errors and
the current page advancing normally. The entire symptom, including the
blank Preview page, is fully explained by the redisplay/`#default_value`
gate above; there is no second, separate save-path bug.

**Comparison element (`WebformLikert`)**: keeps
`'default_value' => []` declared, never unset — the direct, verifiable
reason the equivalent Likert element on the same real form persists
correctly across the same navigation.

## Decision

Keep `'default_value' => []` declared in `defineDefaultProperties()`
(matching `WebformLikert`'s own precedent — `WebformRankingConverter::
matrixToCanonical([])` already resolves to the correct empty canonical
shape, so `[]` is a safe default with no change needed to `prepare()`'s
existing fallback logic).

Suppress only the generic admin-form widget, in `form()`, *after*
`parent::form()` has already built it:

```php
unset($form['default']['default_value']);
```

This removes just the rendered field a site builder would otherwise see
(and could misuse), without touching the property declaration
`populateElements()` depends on. The original concern — no way to
configure a scalar/YAML default that could crash the converter — stays
fully addressed.

## Alternatives Considered

- **Leave `unset($properties['default_value'])` and add a separate
  opt-out for `populateElements()`'s repopulation:** rejected — no such
  hook exists in Webform core; would require patching core or
  overriding `populateElements()` itself, disproportionate to the
  problem.
- **Override `hasProperty()` to special-case `'default_value'`:**
  rejected — `hasProperty()` is a simple, widely-relied-on primitive
  (Webform core and presumably other contrib elements assume it mirrors
  `getDefaultProperties()` exactly); special-casing it here would be a
  surprising, hard-to-discover exception for the one caller that
  matters, functionally identical to keeping the property declared but
  strictly worse for anyone reading `hasProperty()`'s implementation
  later.
- **Give `default_value` a non-empty, form-shaped default instead of
  `[]`:** rejected — unnecessary; `[]` already round-trips correctly
  through `matrixToCanonical()`, and a richer default has no clear
  benefit over matching `WebformLikert`'s own precedent.

## Consequences & Trade-offs

### Positive

- Ranking selections now survive wizard "Previous" navigation, draft
  resume, and submission editing, matching every other field type.
- The Preview page now correctly shows ranking values (a consequence of
  the same fix, not a separate change).
- The admin config form still shows no generic "Default value" widget
  for this element — the original #129-unrelated concern stays
  resolved.

### Negative / Caveats

- The property-declaration removal and the admin-widget removal are
  now two separate lines of code in two different methods
  (`defineDefaultProperties()` and `form()`) rather than one — a reader
  touching either needs to know they're deliberately split, not
  accidentally duplicated-then-forgotten. This ADR is the pointer for
  that.
- Any *future* Webform core caller that starts relying on
  `hasProperty('default_value')` for some other purpose (there is
  currently only the one) would need to be re-evaluated against this
  decision — the fix assumes today's single-caller reality.

## Related Code & Docs

- **Files:** `src/Plugin/WebformElement/WebformRanking.php`
- **Related:** none directly; contrast with ADR-0012/ADR-0019/ADR-0020/
  ADR-0023, all of which are client-side (`#states`-driven) visibility/
  sync concerns — this is a purely server-side admin-config-vs-
  submission-repopulation conflict, unrelated to any of that JS
  machinery.
- **GitHub Issues:** #129
