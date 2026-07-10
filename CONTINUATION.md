# webform_ranking — Continuation Bundle

## Goal
Custom Drupal 10/11 module: a Webform element for ranking a set of admin-defined
items (1st, 2nd, 3rd...), with an N/A opt-out, two display styles (radio
matrix / drag-drop list), conditional per-item visibility, and full
server+client validation. No suitable existing contrib module was found.

## Architecture

**Canonical value shape** (used for all in-memory processing — validation
rules, the visibility resolver — matrix and dragdrop are both just
producers/consumers of this):
```php
['values' => ['item_a', 'item_c'], 'na' => ['item_b']]
```
`values` is an ordered list, position = rank − 1. Items absent from both
arrays are "not applicable" (e.g. conditionally hidden), not an error.

**Storage shape is different from canonical — this matters.** Webform's
submission storage (`WebformSubmissionStorage::saveData()`) can only
persist a scalar, or (for elements marked `composite = TRUE`) a flat map of
scalar-valued properties. It has no way to store canonical shape (both
`values` and `na` are themselves arrays) — handing it canonical shape
silently corrupts to the literal string `"Array"` on save (a real bug hit
and fixed this session, plus a follow-on `getTestValues()` gap it exposed
in Webform's Test tab — see Key Design Decision #7). So the element's
final `#value` after `validateWebformRanking()` — and therefore whatever
`$webform_submission->getElementData()` returns — is the flat
`WebformRankingConverter::canonicalToMatrix()` shape instead:
```php
['item_a' => '2', 'item_b' => 'na', 'item_c' => '1']
```
`WebformRanking::prepare()` converts back to canonical (via
`matrixToCanonical()`) when populating `#default_value` from an existing
submission, so `buildMatrix()`/`buildDragDrop()`/`valueCallback()`'s
no-input fallback only ever see canonical shape. The plugin's
`getItemRankValue()` takes stored (flat) shape directly, not canonical.

**Files:**
- `src/WebformRankingConverter.php` — pure static conversion functions:
  `matrixToCanonical()`, `canonicalToMatrix()`, `dragdropToCanonical()`,
  `canonicalToDragdrop()`, `accountedFor()`. No Drupal deps.
- `src/WebformRankingVisibilityResolver.php` — service, wraps Webform's
  `webform_submission.conditions_validator` (class
  `WebformSubmissionConditionsValidator::validateConditions()`). Resolves
  which configured items are currently visible given a submission.
  **Fails closed**: no submission context → any item with a `states`
  condition is excluded (only unconditional items pass). Deliberate,
  tested, guarded by a regression test.
- `src/Element/WebformRanking.php` — the Form API `FormElement`
  (`#type => webform_ranking`). Has `valueCallback()`,
  `processWebformRanking()` (builds matrix or dragdrop sub-render array),
  `validateWebformRanking()` (all server-side validation rules).
- `src/Plugin/WebformElement/WebformRanking.php` — the `WebformElementBase`
  plugin: annotated `composite = TRUE` (storage-shape reason above), admin
  config form (`form()`), `defineDefaultProperties()` /
  `defineTranslatableProperties()` (NOT `getDefaultProperties()` directly —
  that bypasses Webform's caching/alter-hook layer), `prepare()` (also
  converts `#default_value` storage-shape → canonical),
  `getElementSelectorOptions()` (matrix-only #states selector fix, see
  below), `getItemRankValue()`, `getTestValues()` (Webform Test-tab support),
  `validateConfigurationForm()`. The `items` `webform_multiple` field has
  **no `#key`** — an earlier version set `#key => 'value'`, which silently
  strips the `value` sub-field out of each row and uses it as the array key
  instead (`WebformMultiple::convertValuesToItems()`), breaking every
  consumer's `$item['value']` assumption (a real "Undefined array key
  'value'" bug hit and fixed this session). Uniqueness of `value` across
  rows is instead enforced in this plugin's own `validateConfigurationForm()`.
- `webform_ranking.services.yml` — registers the resolver, wired to
  `@webform_submission.conditions_validator` (NOT `webform.conditions_validator`
  — that was a wrong guess, corrected against a real error).
- `config/schema/webform_ranking.schema.yml` — per-item `states` is
  `type: ignore` (irregular nested structure).
- `js/webform_ranking.matrix.js` — rank-exclusivity (disable-on-select
  across rows, real `disabled`+`aria-disabled`), `aria-live` announcements.
  Recompute-from-scratch on every change.
- `js/webform_ranking.dragdrop.js` — Pointer Events reorder engine
  (mouse/touch/pen unified, NOT native HTML5 DnD — inconsistent across
  browsers, no touch support). Server-rendered move-up/move-down buttons
  are the primary always-present interaction; arrow keys are a shortcut;
  drag is a convenience layer. All three funnel through one `sync()`.
  Renumbers "N of M" position indicator based on `offsetParent !== null`
  (currently states.js-visible items) — dynamic ranks for dragdrop.
- `js/webform_ranking.items_admin.js` — admin-form-only progressive
  disclosure for the per-item conditional-visibility YAML field (see
  below). Structure-agnostic DOM-walk to scope checkbox→row.
- `tests/src/Unit/` — `WebformRankingConverterTest`,
  `WebformRankingVisibilityResolverTest` (incl. fail-closed regression
  test). Both pass, 0 warnings.
- `tests/src/Kernel/` — `WebformRankingValidationKernelTest` (calls
  `validateWebformRanking()` directly w/ hand-built `#value`, comprehensive
  rule coverage incl. forged-input cases) and `WebformRankingValidationTest`
  (calls real `valueCallback()` then `validateWebformRanking()` — NOT via
  `FormBuilder::submitForm()`, see Known Gaps). Both pass, 0 warnings/errors
  as of last run.

## Key Design Decisions (with rationale)
1. **Matrix radios**: one radio group per *item* (row), not per rank
   column — makes "each item = exactly one rank" a natural constraint.
2. **Non-sequential-array rejection check was removed** (was dead code —
   an earlier filtering step already reindexes via `array_values()`, and
   even a forged non-sequential array is harmless once reindexed since
   rank = iteration order, not literal keys).
3. **`#states`/Likert-bug fix**: `getElementSelectorOptions()` exposes
   per-item selectors for matrix (real DOM radio inputs) but **NOT for
   dragdrop** (rank only exists inside a comma-joined hidden input;
   `states.js` can't parse that) — explicit, documented limitation.
4. **Per-item conditional visibility admin UI**: originally
   `webform_element_states` (Webform's visual conditions builder) nested
   inside the `webform_multiple` items table — **crashed in production**
   (`TypeError` in `WebformCodeMirror::validateWebformCodeMirror()`,
   array reaching a YAML-string validator). Replaced with a plain
   `webform_codemirror` YAML-mode field. Real UX cost (raw YAML vs. visual
   builder), accepted as fallback.
5. **Progressive disclosure on top of that YAML field** (latest change):
   unchecked-by-default checkbox per row, hides/shows the YAML field via
   plain JS (deliberately NOT Drupal's States API again, given #4).
   Checkbox is **never persisted** — stripped in `validateConfigurationForm()`.
   Presence of YAML content is the sole source of truth. Unchecking clears
   the field (so hidden-but-stale YAML can never silently reactivate).
6. **Kernel test for `valueCallback()`+`validateWebformRanking()` bypasses
   `FormBuilder::submitForm()` entirely** — two consecutive wrong guesses
   at Drupal's request-method/submission-detection internals (tried
   `setProgrammed(TRUE)`, still failed) led to sidestepping that layer
   rather than guessing a third time. Calls the real production methods
   directly instead. **Trade-off**: no test exercises `#process` (building
   the matrix sub-elements) end-to-end anymore.
7. **Storage shape != canonical shape** (see Architecture section above).
   First found via a real browser error on saving the element to a form:
   `Undefined array key "value"` in `buildMatrix()`, root-caused to
   `#key => 'value'` on the items `webform_multiple` field silently
   stripping `value` out of each configured item row. Fixing that surfaced
   a second, worse bug on submit: Webform's storage layer was casting the
   whole canonical `{values, na}` array to the literal string `"Array"`
   on save (confirmed via `webform_submission_data` SQL rows and
   `$submission->getData()`). Considered JSON-serializing the value at the
   storage boundary instead (simpler, fully preserves canonical shape,
   zero test churn) but rejected in favor of marking the element
   `composite = TRUE` and storing the flat item→rank map — matches
   Webform's own precedent (`WebformMapping`, `WebformLikert`, both
   `composite = TRUE` with scalar-per-property shapes) and keeps per-item
   rank data natively queryable/exportable via Webform's own Views/CSV
   machinery, at the cost of a `prepare()`/`validateWebformRanking()`
   conversion boundary and 2 updated test assertions. Marking it composite
   also exposed a *third* bug: Webform's Test tab generates fallback test
   data via generic name/type pattern-matching for elements without a
   `getTestValues()` override, which could hand back an arbitrary scalar
   string that then failed `canonicalToMatrix()`'s array type hint —
   fixed by implementing `getTestValues()` to generate a real random
   full ranking in the correct flat shape.

## Pattern Worth Knowing
Several rounds of this thread involved *wrong, unverified guesses* about
Drupal/Webform internals (service IDs, `FormBuilder` submission detection,
method visibility, `getDefaultProperties()` vs `defineDefaultProperties()`)
that only got caught because the user ran real tests/browser actions and
reported exact errors back. Treat any Drupal-core/Webform-internals claim
in this codebase as a **hypothesis pending execution**, not fact — several
already were wrong once. When in doubt, search for real source/error
evidence rather than assert confidently.

## Known Gaps / Pending Tasks (not hidden, explicitly flagged in code)
- **`#process` has zero automated coverage** — needs Functional/Nightwatch,
  not Kernel (renders actual HTML).
- **Resolver's "item becomes visible because real trigger matched" path**
  is untested — only fail-closed/no-context side is covered. Needs a real
  `Webform` entity + `WebformSubmissionForm` integration test.
- **Matrix style has no dynamic rank renumbering** (dragdrop does). The
  `<thead>` is static, sized to full configured item count regardless of
  which rows are currently states.js-visible.
- **`findRowContainer()` in `items_admin.js` is unverified against real
  DOM** — structure-agnostic by design (walks up until exactly one YAML
  wrapper matches) specifically because `webform_multiple`'s row markup
  wasn't confirmed. First thing to check if the toggle misbehaves.
- **No visual CSS design pass** — current CSS is structural/layout only.
- **`webform_element_states` nested-widget crash root cause never actually
  diagnosed** — worked around, not fixed/understood. Could theoretically
  be revisited if raw-YAML UX becomes a real problem.
- JS unit tests (Jest/jsdom) and Nightwatch browser tests were discussed
  but never built — user chose Kernel test for `validateWebformRanking()`
  instead when offered a menu of options.
- `validateConfigurationForm()`, `prepare()` signatures used against
  `WebformElementBase` are less independently verified than
  `form()`/`getDefaultProperties()` (those got fixed via real errors);
  worth watching for surprises there too.
- **No custom results/view formatting** (`formatHtmlItem()`/
  `formatTextItem()` not overridden) — the submission "View" page,
  results table, and CSV export currently fall through to
  `WebformElementBase`'s generic default formatting, which was written
  assuming a scalar value. It hasn't been checked against the module's
  actual stored shape (flat item→rank map, see Key Design Decision #7)
  and likely needs per-item-label-aware formatting (à la
  `WebformLikert::formatHtmlItem()`) to look right — a display/UX task,
  separate from the storage-correctness fix that surfaced it.

## Constraints
- Target: Drupal ^10.1 || ^11, Webform ^6.2 (composer.json).
- Security-advisory-policy-quality module is the bar (per original ask).
- No PHP/Drupal execution available in prior environment — all fixes after
  the first several messages were driven by the user's real test/browser
  output, not independent verification. Claude Code presumably *can*
  execute — recommend actually running things rather than continuing the
  guess/correct loop where avoidable.
