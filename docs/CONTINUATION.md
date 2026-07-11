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
  Recompute-from-scratch on every change. Structure-agnostic by
  construction (groups radios by `name`, finds a rank's header by DOM
  position) — needed zero changes when `buildMatrix()`'s cell layout was
  fixed (Key Design Decision #9), which is what made that fix low-risk.
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
  rule coverage incl. forged-input cases), `WebformRankingValidationTest`
  (calls real `valueCallback()` then `validateWebformRanking()` — NOT via
  `FormBuilder::submitForm()`, see Known Gaps), and
  `WebformRankingPluginTest` (the plugin itself, instantiated via
  `plugin.manager.webform.element`: `getItemRankValue()`,
  `getTestValues()`, `getElementSelectorInputValue()` and
  `getElementSelectorOptions()` for both matrix and dragdrop selector
  shapes (mocked `WebformSubmissionInterface`, see Key Design
  Decisions #12/#13), and private `resolveRankDisplay()` via
  reflection — NOT `formatHtmlItem()`/`formatTextItem()` themselves,
  which need a real Webform + WebformSubmission and are left to the
  same Functional/Nightwatch tier as `#process`, see Known Gaps). All
  pass, 0 warnings/errors as of last run (64 tests total). Note: the
  dragdrop `#states` reorder→reveal behavior itself (the `sync()`/JS
  side of Key Design Decision #13) has no automated coverage — it was
  verified manually in a browser, same caveat as `#process` below.

## Key Design Decisions (with rationale)
1. **Matrix radios**: one radio group per *item* (row), not per rank
   column — makes "each item = exactly one rank" a natural constraint.
2. **Non-sequential-array rejection check was removed** (was dead code —
   an earlier filtering step already reindexes via `array_values()`, and
   even a forged non-sequential array is harmless once reindexed since
   rank = iteration order, not literal keys).
3. **`#states`/Likert-bug fix**: `getElementSelectorOptions()` exposes
   per-item selectors so an admin building a condition is only ever
   offered sub-selectors resolving to a real scalar, never the
   composite array as a whole. Originally matrix-only, with dragdrop
   flagged as a known, deliberate gap (rank only existed inside a
   comma-joined hidden input, which `states.js` can't parse) — dragdrop
   support was added later via a second per-item data channel; see Key
   Design Decision #13. (The selector string this method built had its
   own separate bug — a bogus `[rank]` suffix that never matched a
   real input — fixed later; see Key Design Decision #10.)
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
8. **Results/CSV formatting** (`formatHtmlItem()`/`formatTextItem()` in
   the plugin): the base class's default formatting assumes a scalar
   value and hit a real `TypeError` in `Html::escape()` (an array
   reaching `#plain_text`) the first time a submission's "View" page
   was loaded after this element became composite. Fixed by rendering
   each configured item as "Label: rank" (or the admin's `#na_label`,
   or "Not ranked"), reusing `getRankLabels()` (bumped `public` on the
   Element class for this) so results match whatever rank labels the
   live form uses. Items are ordered by **rank**, not configured order
   — ranked first (in rank order), then N/A, then never-accounted-for
   — via `WebformRankingConverter::orderByRank()` (moved there from a
   plugin-private method once it got a Unit test — see
   `tests/src/Unit/` above — since it has no Drupal dependencies of
   its own). Each line is self-labeled ("Pizza: 1st"), so reordering
   loses no information; this only
   applies to the `value`/default format — `raw` format also now
   follows rank order for consistency, but shows the raw rank token
   instead of a resolved label.
9. **Matrix radio layout: one `radio` per cell, not one `radios` bundle
   per row.** Reported bug: all rank options rendered stacked under the
   1st-rank column only, none under 2nd/3rd. Root cause: the row's
   single `#type => 'radios'` bundle is one element occupying one table
   cell — `Table::preRenderTable()` turns each row's *direct* children
   into separate `<td>`s, and a `radios` bundle is only one child, so
   every option landed in that one cell regardless of `#options` order.
   Fixed by building one bare `#type => 'radio'` per rank column
   (mirroring core's `Radios::processRadios()`: same
   `#return_value`/`#default_value`/`#parents` pattern, explicit `#id`
   via `Html::getUniqueId()` to avoid duplicate DOM ids since siblings
   share `#parents`), each its own array key/cell, so they line up
   under `#header`'s columns positionally. Sharing the row's `#parents`
   across all of a row's radios (unchanged from before) is what still
   makes them one mutually-exclusive native radio group and keeps the
   submitted field name identical (`preference[matrix][pizza]`) — no
   changes needed to `WebformRankingConverter`, `valueCallback()`, or
   the JS. Bonus: each radio now gets a real (invisible) title like
   "Pizza: 1st" instead of the old bundle's blank per-option label.

10. **`getElementSelectorOptions()`'s `[rank]` selector bug — now
    fixed** (previously flagged here as a latent, unverified bug; the
    user then hit it for real). Built matrix-item `#states` selectors
    as `"{key}[matrix][{item}][rank]"`, but the actual matrix radio
    field name has always been `{key}[matrix][{item}]` (no `[rank]`
    suffix; see Key Design Decision #9's `#parents`) — the selector
    never matched a real DOM input. Reported symptom: 3 elements each
    conditioned on a different ranking item being ranked 1st never
    reacted to rank changes at all — states.js binds purely by
    querying the live DOM for the selector string, so a selector
    matching nothing silently never attaches a listener. Fixed by
    dropping the `[rank]` suffix. **Important trade-off discovered
    while fixing**: this only fixes selectors the admin UI generates
    *going forward* — an already-saved `#states` condition has the
    literal broken selector string baked into its config `#states`
    array as data, so existing conditions (like the ones the user had
    already configured) needed their saved config corrected directly,
    not just the code. Verified live in a browser: toggling ranks now
    correctly shows/hides each of 3 dependent elements with no page
    reload, including the "switch which item holds rank 1" case
    (verified through our own rank-exclusivity JS, which disables a
    rank everywhere else once assigned — an easy false negative if you
    click a still-disabled radio expecting it to register).

11. **Matrix ranks must be sequential from 1st place, no gaps** — real
    reported bug: item_b/item_c ranked 2nd/3rd, item_a marked N/A,
    `#required_all` satisfied (every item accounted for), but nothing
    ranked 1st. `matrixToCanonical()`'s output only preserves relative
    order, not literal rank numbers, so a skipped leading rank is
    silently "coalesced" away once canonical — meaning a live `#states`
    condition checking "is item X ranked 1st" never fires client-side
    (nothing in the DOM is literally '1'), even though item X would be
    stored as 1st on submit. Fixed with
    `WebformRankingConverter::matrixRanksAreSequential()` (pure, unit
    tested) plus a stash of the *raw* per-item matrix input on
    `$element['#_matrix_raw_input']` in `valueCallback()` (canonical
    shape alone can't detect the gap — see the method's own docblock),
    checked in `validateWebformRanking()` against currently-visible
    items only. Verified live in a browser: submitting exactly this
    scenario now shows "ranks must be assigned starting from the top,
    with no gaps..."; a valid sequential ranking (1st/2nd used, 3rd
    N/A) submits cleanly.

12. **`getItemRankValue()` existed but was never wired up — real
    `Warning: Array to string conversion` hit live.** The plugin had
    `getItemRankValue()` (Key Design Decision area, added for exactly
    this purpose, unit-tested via `WebformRankingPluginTest`) but no
    caller ever invoked it. `WebformElementBase`'s default
    `getElementSelectorInputValue()` (used by the server-side
    conditions validator, `WebformSubmissionConditionsValidator`, to
    resolve what value a `#states` selector currently points to) does
    composite-key extraction assuming fixed sub-property keys (e.g.
    `WebformName`'s 'first'/'last') via `$value[$composite_key]`. Our
    per-item selectors (`getElementSelectorOptions()`) use the item's
    *value* as the third selector segment (e.g.
    `"preference[matrix][pizza]"`), which isn't a real key in the flat
    item-value => rank storage map, so the generic extraction silently
    failed to reduce it — the whole flat map reached
    `checkConditionTrigger()`, which then did `(string) $element_value`
    on an array. Confirmed via watchdog before the fix: the exact PHP
    warning, with `element_value=Array(...)`. Fixed by overriding
    `getElementSelectorInputValue()` in the plugin to parse the item
    value out of the selector and call `getItemRankValue()` directly;
    falls back to `parent::` for anything that isn't a matrix per-item
    selector. Verified live: submitting a valid ranking produces zero
    watchdog warnings and correct stored data.

13. **Drag/drop items can now be used as `#states` trigger sources**
    (previously a documented, deliberate gap — see the old text of
    Key Design Decision #3). Root cause recap: `#states`'s trigger
    vocabulary (`value`/`pattern`/`empty`/etc., both client-side in
    `states.js` and server-side in
    `WebformSubmissionConditionsValidator::checkConditionTrigger()`)
    only ever compares *one selector's raw value* — it has no concept
    of "parse this delimited string and pull out field N." Drag/drop's
    only real DOM inputs are two hidden fields, `order` (a comma-joined
    CSV of ranked item values) and `na` — an item's rank exists only as
    its *position* in that CSV, which isn't a thing any trigger type
    can express, so there was no real input to build a per-item
    selector against.
    Fixed by adding a **second, purely-derived data channel**: one
    extra hidden input per item (`{key}[dragdrop][rank][{item}]`,
    `WebformRanking::buildDragDrop()`), holding that item's own
    rank/`na`/`''`. `element.dragdrop`'s `sync()` — the single function
    every reorder path (drag, buttons, arrow keys) already funnels
    through — writes these alongside `order`/`na` and dispatches a
    native `change` event on each (`states.js`'s `value` trigger listens
    for `keyup`/`change`; a programmatically-set `.value` fires
    neither on its own). `getElementSelectorOptions()` now emits one
    selector per item for *both* styles; `getElementSelectorInputValue()`
    resolves either shape (`[matrix][{item}]` or
    `[dragdrop][rank][{item}]`) down to the same `getItemRankValue()`
    call, since storage is unconditionally the flat matrix-shaped map
    regardless of display style (`validateWebformRanking()` always
    finishes with `canonicalToMatrix()`) — no style branching needed
    once the item value is extracted from the selector.
    **This is a second source of truth, and that's a real, permanent
    risk, not just a one-time implementation detail**: nothing
    *enforces* that the per-item echo stays in lockstep with
    `order`/`na` except the convention that `sync()` is the only place
    any of the three are ever written. A future change to reordering
    logic that updates `order`/`na` through a different path (e.g. a
    quick fix that sets `orderInput.value` directly instead of calling
    `sync()`) would silently desync `#states` from the actually-
    submitted ranking — validation/storage would still be correct
    (they only ever read `order`/`na`), but a dependent field could
    show/hide based on stale rank data with no error raised anywhere.
    Anyone touching `element.dragdrop`'s reorder paths should route
    through `sync()`, never write `order`/`na`/the rank inputs
    directly elsewhere, and re-verify this specific scenario
    (`#states` triggered by a dragdrop item's rank) if that function is
    restructured. The equivalent single-source-of-truth risk for matrix
    doesn't exist, since matrix's real radio inputs *are* the storage
    values — nothing there needed a second channel.
    A second, unrelated bug surfaced during manual verification: the
    rank-echo hidden input was initially given the *same*
    `data-webform-ranking-value` attribute as the item container, so a
    generic `[data-webform-ranking-value="x"]` query could silently
    match whichever element happened to render first in the DOM
    instead of the intended one (hit for real: a test script's
    `querySelector` meant for the item container matched the hidden
    input instead, then failed on a null `move-up` button). Fixed by
    giving the echo input its own distinct attribute,
    `data-webform-ranking-rank-for`.
    Verified live in a browser: promoting an item to 1st via the
    move-up button revealed its dependent markup element instantly, no
    reload; moving a different item to 1st correctly hid the first and
    revealed the second; marking an item N/A correctly showed `'na'`
    and re-promoted the next item; the final submission stored the
    correct data with zero watchdog warnings.

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
- **Dragdrop's per-item `#states` rank echo (Key Design Decision #13) has
  no automated coverage of the JS/DOM behavior itself** — `sync()` writing
  and dispatching `change` on the echo inputs was verified manually in a
  browser only. The PHP-side selector generation/resolution is unit-tested;
  the actual reorder→live-reveal behavior isn't. Same Functional/Nightwatch
  tier as `#process` above; also the piece most likely to silently regress
  if `element.dragdrop`'s reorder logic is ever restructured without
  routing through `sync()` (see #13's staleness-risk note).
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
- **Results/view formatting done, but only the `value`/`raw` formats**
  (see Key Design Decision #8) — `table` format (like
  `WebformMapping`/`WebformLikert` offer) isn't implemented; falls
  through to the `value` format's item list instead. Not attempted
  since nothing's asked for it yet.

## Constraints
- Target: Drupal ^10.1 || ^11, Webform ^6.2 (composer.json).
- Security-advisory-policy-quality module is the bar (per original ask).
- No PHP/Drupal execution available in prior environment — all fixes after
  the first several messages were driven by the user's real test/browser
  output, not independent verification. Claude Code presumably *can*
  execute — recommend actually running things rather than continuing the
  guess/correct loop where avoidable.
