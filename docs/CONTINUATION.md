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
- `js/webform_ranking.items_admin.js` — admin-form-only per-item
  conditional-visibility YAML field, presented in a `Drupal.dialog()`
  modal per item (Key Design Decision #17; previously a per-row
  checkbox toggle, which never worked). Uses `const`/`let`, unlike this
  module's other JS files — see the module's tracked refactor issue.
- `tests/src/Unit/` — `WebformRankingConverterTest`,
  `WebformRankingVisibilityResolverTest` (incl. fail-closed regression
  test). Both pass, 0 warnings.
- `tests/src/Kernel/` — `WebformRankingValidationKernelTest` (calls
  `validateWebformRanking()` directly w/ hand-built `#value`, comprehensive
  rule coverage incl. forged-input cases), `WebformRankingPipelineTest`
  (calls real `valueCallback()` then `validateWebformRanking()` — NOT via
  `FormBuilder::submitForm()`, see Known Gaps), and
  `WebformRankingPluginTest` (the plugin itself, instantiated via
  `plugin.manager.webform.element`: `getItemRankValue()`,
  `getTestValues()`, `getElementSelectorInputValue()` and
  `getElementSelectorOptions()` for both matrix and dragdrop selector
  shapes (mocked `WebformSubmissionInterface`, see Key Design
  Decisions #12/#13), `prepare()`'s string-vs-array `states`
  normalization (Key Design Decision #15; `setUp()` sets
  `webform.settings:element.allowed_tags` directly rather than
  `installConfig(['webform'])`, which pulls in config *entities*
  needing DB schema this minimal test doesn't install), and private
  `resolveRankDisplay()` via reflection — NOT
  `formatHtmlItem()`/`formatTextItem()` themselves, which need a real
  Webform + WebformSubmission and are left to the same
  Functional/Nightwatch tier as `#process`, see Known Gaps). All pass,
  0 warnings/errors as of last run.
- `tests/src/FunctionalJavascript/` — `WebformRankingDragdropJavaScriptTest`
  and `WebformRankingMatrixJavaScriptTest` (see Key Design Decision #14):
  real-browser coverage via `WebDriverTestBase`/Mink, driven by a real
  WebDriver Chrome session (`ddev/ddev-selenium-standalone-chrome`, a
  local-only setup step — see #14 for why). Covers what Unit/Kernel tests
  structurally can't: `#process` rendering, drag/pointer/keyboard/button
  reordering, N/A toggling, and live `#states` reactions for both display
  styles. Note: the dragdrop `#states` reorder→reveal behavior's
  underlying `sync()`/JS mechanics (Key Design Decision #13) are now
  covered by `testStatesReactToRankSelection()` in the dragdrop test.
  `testGradualPointerDragReordersItems()` (Key Design Decision #16)
  additionally covers a multi-step, W3C-Actions-driven drag distinct
  from `testPointerDragReordersItems()`'s single-jump `dragTo()`.
  `WebformRankingItemsAdminJavaScriptTest` (Key Design Decision #17)
  covers the per-item conditional-visibility dialog on the admin config
  form — requires `webform_ui` in `$modules`, unlike the other
  FunctionalJavascript tests here, since that's what provides the
  element edit form route.

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

14. **FunctionalJavascript (not Nightwatch) chosen for browser-driven
    tests** (GitHub issue #6). Drupal core uses Nightwatch (Node.js/npm
    toolchain) for its own JS behavior tests, but Webform — the module
    this codebase mirrors conventions from throughout — uses
    FunctionalJavascript instead: pure PHPUnit, runs via the same
    `ddev phpunit` command already used for Unit/Kernel tests, no
    separate toolchain. Chosen over Nightwatch for that reason, and
    because it turned out to solve a real problem hit along the way
    (see next point).
    **Local environment requirement, not tracked in git** (`.ddev/` is
    gitignored in this repo): FunctionalJavascript needs a real
    WebDriver-compatible browser. Install with
    `ddev add-on get ddev/ddev-selenium-standalone-chrome` (the
    official companion to the already-installed `ddev-drupal-contrib`
    addon this project uses) and `ddev restart`. Anyone running these
    tests locally needs to do this themselves once; it's not something
    `ddev phpunit` alone provides.
    **Also hit and fixed a real, unrelated environment bug getting
    here**: every FunctionalJavascript test initially failed with a
    502 from nginx — `upstream sent too big header while reading
    response header from upstream`. Root cause: `.ddev/nginx_full/
    nginx-site.conf`'s `fastcgi_buffer_size` (32k, already larger than
    nginx's tiny default) still wasn't enough for the large cache-tag
    response headers Drupal's WebDriverTestBase environment produces
    with many core modules enabled. Fixed by bumping to
    `fastcgi_buffers 16 64k; fastcgi_buffer_size 128k;` — but doing
    that turned out to be a two-step problem: DDEV auto-regenerates any
    file starting with the literal marker string `#ddev-generated` on
    `ddev restart`, and the *first* attempt to remove that marker
    accidentally left the literal substring `#ddev-generated` sitting
    inside my own explanatory comment about why I'd removed it — which
    was enough for DDEV's marker detection to keep re-triggering
    regeneration. Had to phrase the comment without using that exact
    string for the customization to actually stick.
    **A second real bug found and fixed while writing the drag/drop
    test** (`data-webform-ranking-value` collision): see
    `js/webform_ranking.dragdrop.js`'s comment near
    `rankInputsByValue` — not repeated here, cross-referenced from Key
    Design Decision #13 already.
    **Significant finding for GitHub issue #3** (drag/drop pointer
    reorder reportedly not working): a real WebDriver-driven mouse
    drag (`NodeElement::dragTo()`, genuine W3C Actions
    pointerMove/pointerDown/pointerUp, not synthetic JS events) *does*
    correctly reorder items — directly contradicting the original
    report. An earlier attempt to reproduce the bug via chrome-cli's JS
    execution had turned out to run in an isolated JS world separate
    from the page's real global scope (confirmed: `window.Drupal`,
    `jQuery`, and `drupalSettings` were all `undefined` from that
    execution context), making it unable to reliably observe or
    intercept the actual page-world listeners — a red herring, not
    evidence of a real bug. What the WebDriver test clarified instead:
    `dragTo()` moves the pointer to the destination element's top-left
    corner, landing in the *upper half* of its bounding box, and the
    production pointermove handler's own midpoint check
    (`event.clientY < rect.top + rect.height / 2`) correctly treats
    that as "insert before," which is exactly what happened. Per-user
    decision: don't chase this further on this branch — issue #3 is
    left open for separate, deeper investigation (possibly
    environment/hardware-specific — real trackpad input generates many
    rapid incremental `pointermove` events rather than one jump; worth
    testing a multi-step drag path specifically) rather than closed out
    from this finding alone.
    New test files: `tests/src/FunctionalJavascript/
    WebformRankingDragdropJavaScriptTest.php` (pointer drag, move-up/
    down buttons, arrow keys, N/A toggle, dragdrop `#states` live
    reveal) and `WebformRankingMatrixJavaScriptTest.php` (rank
    exclusivity, aria-live announcements, matrix `#states` live
    reveal). Also discovered along the way: `NodeElement::keyDown()`/
    `keyUp()` go through Mink's bundled `syn.js`, a JS-simulated
    keyboard event that only sets the legacy `keyCode`/`which`
    correctly — its `.key` string comes through wrong (`chr($code)`,
    not e.g. `'ArrowDown'`). Since this element's JS checks the modern
    `event.key`, the arrow-key test sends a real native key via the
    WebDriver session's legacy element-value endpoint directly
    (`\WebDriver\Key::DOWN_ARROW` via `postValue(['text' => ...])` —
    `'text'`, not the older `'value'` array form, since this
    environment runs in W3C WebDriver mode) instead of
    `NodeElement::keyDown()`.

15. **Per-item conditional visibility (#states YAML) never actually
    worked — root cause: the YAML string was never decoded into an
    array.** Reported bug (GitHub issue #5): an item's configured
    condition never hid/showed it, even though the identical condition
    applied correctly to an ordinary control element used as an A/B
    comparison. Confirmed live: the item's `data-drupal-states`
    attribute was `"invisible:\n  ...` — the JSON-encoding of a
    **string** (the raw YAML source), not a JSON object. Traced to
    `web/modules/contrib/webform/src/Element/WebformCodeMirror.php`'s
    `validateWebformCodeMirror()`: it only auto-decodes a submitted
    YAML string into an array when `#default_value` is already an
    array, or when `'#decode_value' => TRUE` is set — neither was true
    for our per-item `states` field (its default comes from stored
    config via `#webform_multiple`, which for a not-yet-fixed item was
    itself a string). Without that, `$item['states']` stayed a raw
    string all the way through `validateConfigurationForm()` into saved
    config (visible in exported config as a YAML block-scalar,
    `states: |- ...`, instead of a real nested mapping) and into
    `buildMatrix()`/`buildDragDrop()`'s `#states` assignment — Drupal's
    `FormHelper::processStates()` JSON-encodes a string exactly as
    happily as an array, so nothing errored anywhere; the condition
    just silently never matched. The same un-decoded string would also
    reach `WebformRankingVisibilityResolver`, which passes it to
    `WebformSubmissionConditionsValidator::validateConditions(array
    $conditions, ...)` — a strictly array-typed parameter — though a
    resulting server-side `TypeError` was only a hypothesis in the
    original issue, not separately confirmed before the fix.
    Fixed with two changes, matching the two ways broken data could
    exist:
    - **Going forward**: added `'#decode_value' => TRUE` to the
      `states` field definition in `form()` — the same, already-real
      Webform-core pattern used by `WebformTable.php` for an identical
      "array edited as YAML text inside another admin form" situation.
      This makes `WebformCodeMirror`'s own validate callback decode and
      save a real array from now on, and gets free "not valid YAML"
      error messaging on the admin form as a side effect.
    - **Self-healing for already-saved config**: `prepare()` now
      normalizes any item whose `states` is still a string, decoding it
      via `WebformYaml::decode()`. Both `buildMatrix()`/`buildDragDrop()`
      and `WebformRankingVisibilityResolver` read `#items` from this
      same prepared `$element`, so one normalization point covers both
      consumers with no separate data migration needed — unlike Key
      Design Decision #10's already-saved-selector fix, which did need
      one, because that bug lived in config as a broken *string value*
      with no single shared read path to intercept it at.
    Verified live in a browser: the previously-broken item now hides in
    lockstep with its A/B-comparison control field with zero manual
    config changes (self-healing fix alone); re-saving the element
    through the actual admin config form afterward produces a properly
    nested YAML mapping in exported config instead of a block-scalar
    string (forward-going fix); final submission with the item
    conditionally hidden completes with no errors and correctly
    excludes it from stored data (the hypothesized resolver `TypeError`
    did not reproduce post-fix — not independently verified pre-fix,
    so treat that specific detail as unconfirmed rather than
    disproven).

16. **GitHub issue #3 root-caused and fixed: pointer capture silently
    breaks after the first mid-drag DOM reorder.** Key Design Decision
    #14 left this open, having only disproven the original report using
    a *single-jump* `dragTo()` drag (which succeeds) and flagging a
    *gradual, multi-step* drag — closer to real trackpad/mouse input,
    which fires many incremental `pointermove` events rather than one —
    as the next thing worth testing. Built directly, since
    `Selenium2Driver::dragTo()` only issues one post-pointerdown move:
    a new test drives the raw W3C WebDriver Actions API
    (`postActions()`) with several discrete `pointerMove` steps in a
    single gesture (`WebformRankingDragdropJavaScriptTest::
    testGradualPointerDragReordersItems()`). Result: the reported bug
    **did reproduce** — the item's order came back completely
    unchanged, unlike the single-jump case.
    Root-caused by bisecting the gesture down to two discrete
    `pointerMove` steps and instrumenting item A with an independent
    `pointermove` event counter (separate from the production
    listener, added via `evaluateScript()`, to distinguish "the browser
    stopped sending events" from "the production JS's own state broke").
    First instrumentation attempt itself gave a false reading (counter
    stayed at 0 the whole time) — traced to a test bug, not a page bug:
    Mink's `Selenium2Driver::evaluateScript()` prepends a bare `return`
    to any script not already starting with one, so a multi-statement
    script starting with a plain assignment (`window.__pmCount = 0;
    document.querySelector(...).addEventListener(...)`) got turned into
    `return window.__pmCount = 0; document.querySelector(...)...` — the
    `return` exits immediately, so the `addEventListener()` call after
    it was dead code that never ran. Fixed the instrumentation itself by
    wrapping it in an IIFE, `(function () { ... })()`, before trusting
    its readings.
    With correct instrumentation: two `pointerMove` actions after
    `pointerDown` (to item B's center, then item C's center) produced
    exactly **two** `pointermove` events on item A — both genuinely
    fired — yet only the **first** one caused a reorder
    (`container.insertBefore()`); the second fired but had no visible
    effect at all, even though its coordinates resolved to a different
    item (confirmed via `elementFromPoint()`). Conclusion: calling
    `container.insertBefore(item, ...)` on the *pointer-captured element
    itself*, mid-gesture, silently breaks that capture in Chromium — no
    error, no observable `lostpointercapture` side effect checked here,
    just no further `pointermove` delivery for the rest of that
    gesture. This is a real, load-bearing distinction from the
    single-jump `dragTo()` case: that test's *one* post-pointerdown move
    already lands on the final destination, so it never needs a *second*
    move to survive a capture-breaking reparent in the middle.
    Fixed with a one-line addition in `js/webform_ranking.dragdrop.js`'s
    `pointermove` handler: re-call `item.setPointerCapture(pointerId)`
    immediately after `insertBefore()`, every time. `item` is still the
    correct, valid node reference after reparenting (only its position
    in the tree changed), so re-capturing is cheap and safe. Verified:
    `testGradualPointerDragReordersItems()` (10-step interpolated drag
    from item A's original position through B's and into C's) now
    passes, and the full existing dragdrop/matrix FunctionalJavascript
    and Unit/Kernel suites (76 tests total) still pass unchanged.
    **Not attempted here** (flagged in the original issue as also worth
    checking, but not needed to explain or fix the confirmed root
    cause above, so left as-is rather than speculative scope creep):
    `touch-action` on `.webform-ranking-dragdrop__item` for touch
    devices, and suppressing native text selection during a drag.
    The text-selection item was addressed separately, in the same PR:
    `user-select: none` (plus `-webkit-`/`-ms-` prefixes) added to
    `.webform-ranking-dragdrop__item` in
    `css/webform_ranking.dragdrop.css`, applied unconditionally rather
    than scoped to `--dragging`, so there's no race between the
    browser arming native selection on `pointerdown`/`mousemove` and
    the JS applying the dragging class. CSS-only, so it can't interact
    with the pointer-capture fix above. `touch-action` for touch
    devices is still open — worth revisiting if a touch-specific
    report comes in.

17. **GitHub issue #4 (per-item conditional-visibility toggle checkbox
    doesn't work) redesigned to a per-item dialog, not fixed in place —
    with two further real bugs caught along the way by testing the
    redesign itself.** The original per-row checkbox (Key Design
    Decision #5) never actually worked: a
    WebformRankingItemsAdminJavaScriptTest confirmed the reported
    symptom (item A's field stayed visible, item B's stayed hidden —
    inverted from expected), consistent with the file's own flagged
    risk that `findRowContainer()`'s walk-up heuristic wasn't verified
    against `#webform_multiple`'s real markup. Rather than debug that
    heuristic, the UI was redesigned per user direction: a per-item
    modal/dialog (Drupal core's `Drupal.dialog()`), triggered by one
    static-label button per item ("Conditions"), showing the same YAML
    field either empty or pre-filled — replacing both the checkbox and
    the two-state "Add"/"Edit (configured)" label a first draft of this
    redesign had (simplified to one static label per user feedback,
    since the dialog itself already shows whether a condition exists).
    This also obsoletes `findRowContainer()` entirely: the trigger
    button is inserted directly next to its own item's wrapper, so
    there's no separate row-matching search to get wrong.
    **Two more real bugs found via testing the redesign itself, not
    guessed:**
    - *Duplicate wrapper matches per item.* `#webform_multiple` applies
      `#wrapper_attributes` to both its own per-item table cell (a
      `<td>`) and the nested `.form-item` div Drupal's Form API
      generates for the same element — so the
      `.webform-ranking-item-states-wrapper` selector matched **two**
      ancestor/descendant elements per item, not one. Every item
      therefore got two trigger buttons and a trigger+dialog nested
      inside another trigger's dialog — this is what the user actually
      saw and described as "an intermediary modal appearing first."
      Confirmed via a live DOM dump in a FunctionalJavascript test
      (4 wrapper matches for 2 items, not 2). Fixed by skipping any
      wrapper match that itself contains another match, keeping only
      the innermost — structure-agnostic (doesn't assume the outer one
      is always a `<td>`), consistent with this file's established
      "don't assume `#webform_multiple` markup" caution.
    - *Drupal's default dialog close handler tore down the field on
      close.* Not passing a `close` option meant `Drupal.dialog()`
      fell back to `drupalSettings.dialog.close`, which calls
      `Drupal.detachBehaviors(event.target, null, 'unload')` — correct
      for disposable, AJAX-loaded dialog content (its intended use
      case), actively wrong here, since this dialog wraps a permanent,
      reused part of the same form, reopened on every click. Caught by
      a real end-to-end test
      (`testConditionPersistsThroughSubmission`): after opening the
      dialog, entering a condition, clicking "Done," and submitting the
      whole element form, the saved config came back with an *empty*
      `states` value — a debug dump showed the edited item's
      `<textarea>` had vanished from the DOM entirely after the dialog
      closed once. Fixed with an explicit no-op `close` option,
      overriding the harmful default.
    A third, narrower issue surfaced and was fixed during the same
    testing pass, specific to test methodology rather than the
    production code: Webform's own CodeMirror JS
    (`webform.element.codemirror.js`) hides the real `<textarea>`
    and debounces syncing its own editor content back to it by 500ms
    (`setTimeout(() => editor.save(), 500)`), so a test that sets the
    textarea's value directly and immediately submits can race that
    stale debounce and have its change silently overwritten. Fixed by
    having the test write through CodeMirror's own API
    (`.CodeMirror.setValue()` + an immediate `.save()`) instead of the
    textarea directly, when CodeMirror is attached.
    New test file: `tests/src/FunctionalJavascript/
    WebformRankingItemsAdminJavaScriptTest.php` — dialog open/edit/clear,
    an already-configured item showing pre-filled content, and the
    submission-persistence check that caught the close-handler bug.
    Also new: `webform_ranking/element.itemsAdmin` now depends on
    `core/jquery` and `core/drupal.dialog` (previously just
    `core/drupal`/`core/once`); the `use_states` checkbox field and its
    stripping in `validateConfigurationForm()` were removed from the
    plugin entirely, since the field no longer exists.

18. **Full Drupal coding-standards cleanup (108 `phpcs` errors -> 0),
    prep work for a drupal.org project application.** Mechanical for
    the most part (missing/malformed docblocks — Drupal's standard
    requires a genuine one-line summary before any elaborating
    paragraph, which `phpcbf` can't write on its own; it can only stub
    an empty docblock), but two real, non-obvious findings surfaced
    along the way:
    - **A `phpcs` false positive from an innocuous method name.**
      `testGetTestValuesRandomOrderIsStillAValidFullRanking` was
      flagged as "not in lowerCamel format" despite genuinely being
      lowerCamel. Root cause: `...StillA` immediately followed by
      `Valid...` produces the substring `AV` — two consecutive
      uppercase letters — which trips a naive camelCase heuristic that
      (reasonably, in the general case) treats consecutive capitals as
      a sign of an acronym rather than two adjacent single/short words.
      Fixed by rewording the method name
      (`...RandomOrderStillProducesValidFullRanking`) to avoid the
      accidental collision, not by suppressing the sniff.
    - **`DrupalPractice`'s `GlobalDrupalSniff` crashes outright on this
      PHP 8.4 environment**, not just warns: it parses `*.services.yml`
      via `symfony/yaml`, and this environment's installed
      `symfony/yaml` version calls a since-deprecated-nullable-param
      pattern that PHP 8.4 raises as a catchable deprecation —
      uncaught, it aborts the whole `phpcbf`/`phpcs` run partway
      through (confirmed via a full stack trace pointing at
      `Symfony\Component\Yaml\Parser`). Not a real code issue in this
      module; worked around by running with `--standard=Drupal` only
      (dropping `DrupalPractice`) for this cleanup pass. Anyone
      re-running a full standards check against a different
      `drupal/coder`/`symfony/yaml`/PHP combination should expect this
      to behave differently — treat a sudden mid-run fatal error here
      as an environment-version mismatch to investigate, not
      necessarily a real problem in the YAML being parsed.
    Also added `LICENSE.txt` (GPL-2.0-or-later) — drupal.org's own
    packaging generates one automatically, but the GitHub-hosted repo
    didn't have one, and GitHub's own UI (license badge, etc.) reads it
    directly from the repo.

19. **GitHub issue #61: per-item `#states` never worked when the trigger
    lived on an earlier wizard page — root-caused and fixed.** Reported
    from a real production form (a multi-page application with a
    `constituency` select on an earlier page and a matrix item
    conditioned on it several pages later). Distinct from Key Design
    Decision #15's fix (that was same-page conditions never decoding
    from YAML at all) and from the separate element-level `#states` gap
    fixed for GitHub issue #57 — this is specifically the *per-item*
    condition, on a *cross-page* trigger.
    Root cause: `WebformSubmissionConditionsValidator::buildForm()`
    walks the webform's *configured* element tree to detect and rewrite
    cross-page conditions, before `\Drupal::formBuilder()` even starts
    running `#process` callbacks — i.e. before `processWebformRanking()`
    has expanded `#items` into real, independently-discoverable
    sub-elements at all. An item's condition, nested inside `#items`, is
    structurally invisible to that walk no matter what, so it never gets
    the same cross-page treatment the element's own top-level `#states`
    correctly receives.
    Fixed by replicating that treatment narrowly, in
    `resolveCrossPageItemStates()`: for each item whose condition
    references a selector resolving to an element outside the
    currently-accessible wizard page (confirmed, via a live
    reproduction, that `$complete_form` already has non-current pages'
    `#access` correctly set to `FALSE` by the time `processWebformRanking()`
    runs — verified empirically, not just inferred), the condition is
    resolved *once* via the same `WebformRankingVisibilityResolver`
    server-side validation already trusts, then applied statically:
    resolved-visible items render unconditionally (no live `#states`,
    since nothing on the current page could ever change the trigger's
    value anyway), resolved-hidden items get `'#access' => FALSE` on
    every cell instead of a `'#states'` attachment pointing at a
    selector with nothing on the page to bind to. Same-page conditions
    (or no condition) are left completely untouched.
    A design question was settled alongside this fix, before
    implementation started: whether to rename the per-item `states` key
    to `#states` (matching the element-level property). Decided against
    — see the GitHub issue #61 discussion for the full reasoning
    (`#`-prefixing is a Render API convention that doesn't apply to
    `#items`' plain config data, and the rename isn't functionally
    connected to this fix at all; it would only be a stored-config
    migration cost for a cosmetic change).
    Verified live in a browser (both directions): the reported `uab`
    item is now fully excluded from rendering (no label, no radios —
    not just hidden via CSS) when its cross-page condition resolves
    true, and renders completely normally when it resolves false.

20. **GitHub issue #63: `#require_first_place`, closing the "mark
    everything N/A" loophole in `#required_all`.** With `#allow_na` on,
    `#required_all` only checks that every visible item is *accounted
    for* (ranked or marked N/A) — a respondent can satisfy that by
    marking every item N/A without ever ranking anything. New
    independent checkbox + companion `#require_first_place_error`
    message (same checkbox+textfield-gated-by-`#states` pattern as core
    Webform's own `required`/`required_error` pair), validated in
    `validateWebformRanking()` right after the N/A-not-allowed check:
    `!empty($element['#require_first_place']) && !$values`. Relies on
    the pre-existing "ranks must be assigned starting from 1st place
    with no gaps" check (which runs earlier in the same method) to make
    a non-empty `$values` array equivalent to "something is ranked
    1st" — no separate rank-position inspection needed.
    Deliberately NOT gated behind `#allow_na` via `#states` (unlike
    `na_label`): it was tempting to hide the checkbox whenever
    `#allow_na` is off, reasoning it's a no-op there, but that's only
    true when `#required_all` is *also* on (every item must be ranked
    already, so 1st is automatic). With `#required_all` off, leaving
    the whole ranking blank is otherwise a valid submission regardless
    of `#allow_na`, and `#require_first_place` still meaningfully
    forbids that specific case — so hiding it on `#allow_na` alone
    would incorrectly suppress a combination where it still matters.
    The error-message field's own visibility, though, is safely gated
    on `#require_first_place` itself (progressive disclosure, not a
    correctness question).
    Custom-message fallback uses `!empty()`, not the `??` pattern
    `#required_error` uses elsewhere in this file — deliberately: since
    `require_first_place_error` defaults to `''` via
    `defineDefaultProperties()`, `??` would treat that empty string as
    "customized" and never fall back to the default translated message.
    Not fixed for the pre-existing `#required_error` (out of scope for
    this issue), but avoided in this new property from the start.
    Test coverage: `WebformRankingValidationKernelTest`, four new cases
    covering rejection (all-N/A), passing (partial ranking, no full
    `#required_all` needed), opt-in (disabled by default), and the
    custom error message. `getTestValues()` (Webform's own Test tab)
    needed no change — it already ranks every item sequentially from
    1st, so it always satisfies this check.

21. **GitHub issue #68: matrix `#required_all`'s native `required`
    attribute silently blocked submission on a same-page conditionally-
    hidden row.** Found the same way as #61/#63's own downstream bugs —
    manual browser testing, not the automated suite: a headless HTTP
    client never runs the browser's own native constraint validation,
    so this class of bug is invisible to Kernel tests entirely.
    `buildMatrix()` baked `#attributes['required'] = 'required'` onto
    every `#required_all` row's radios unconditionally, *before* the
    same method's own per-item `#states` visibility handling ran below
    it. A row hidden by a same-page condition is only hidden
    client-side (states.js toggling display) — the native attribute
    stayed regardless, on a now-hidden, unfocusable control. Browsers
    refuse to submit a form with an unsatisfied required control they
    can't even focus, and do so silently: no Drupal error, no visible
    error, just a console warning. Server-side validation was already
    correct (`WebformRankingVisibilityResolver` already excludes hidden
    items from the `#required_all` check in `validateWebformRanking()`)
    — this was purely a client-side gap.
    Fixed by mirroring the item's own `visible`/`invisible` condition
    onto a `required`/`optional` companion in the *same* `#states`
    array the row already gets — `optional` is core's own alias for
    `!required`, exactly parallel to `invisible` being `!visible` (see
    `Drupal.states.State.aliases`, `core/misc/states.js`) — so
    states.js's own existing `state:required` handler adds/removes the
    native attribute itself, in lockstep with visibility, both on page
    load and on every live change. Gated on `#required_all` specifically
    (confirmed by a regression this fix itself caught in
    `WebformRankingCrossPageItemStatesTest`): with it off there's no
    static `required` attribute to begin with, so mirroring anything
    into `#states` would only add a pointless key nothing ever reads.
    Test coverage: `WebformRankingRequiredIndicationTest` (the
    required/optional mirror appears in the rendered `#states` JSON),
    plus a new `WebformRankingRequiredAllConditionalRowJavaScriptTest`
    (real browser: the native attribute tracks live visibility, and a
    submission with the hidden row's own required constraint no longer
    silently blocked reaches the confirmation page).

22. **GitHub issue #69: validation errors rendered duplicated, once per
    matrix radio, when core's `inline_form_errors` module is enabled.**
    Also found via manual browser testing while verifying #63, same
    session as #68. Root cause: `FormState::getError()` walks an
    element's own `#parents` from the root and returns the *first*
    prefix match — since every matrix radio's `#parents` starts with
    the composite ranking element's own, every radio inherits the
    *exact same* `#errors` value as the composite element itself, not
    just its own. With `inline_form_errors` enabled, its
    `hook_preprocess_form_element()` prints `#errors` inline for any
    `form_element`-themed element lacking `#error_no_message` — every
    matrix radio qualifies (`Radio`'s default `#theme_wrappers` is
    `['form_element']`), and so does the composite ranking element
    itself (this element's own `#theme_wrappers`, per
    `preRenderWebformRanking()`'s docblock). The result: the module's
    own composite-level message (added for #48, via
    `preRenderWebformRanking()`) plus one duplicate per radio, plus a
    second duplicate at the composite level itself once
    `inline_form_errors` restores core's own normally-suppressed
    `errors` template variable there too.
    Fixed by setting `#error_no_message => TRUE` on every matrix radio
    and on the composite element itself — the same convention Webform's
    own composite elements (`WebformElementComposite`,
    `WebformEmailConfirm`, etc.) already use to suppress exactly this.
    Deliberate design decision, flagged in-code for future re-review:
    chose to always suppress `inline_form_errors` and keep this
    element's own rendering as the single code path, rather than
    detecting the module and deferring to its own rendering when
    active — `form-element.html.twig` renders the restored `errors`
    variable as markup-for-markup the same `<div
    class="form-item--error-message">` box this element's own
    `ranking_errors` child already produces, just missing the
    `webform-ranking__errors` class this module's own CSS/tests key
    off, so deferring would add real complexity for no current benefit.
    Test coverage: `WebformRankingErrorDisplayTest` (every matrix radio
    and the composite element itself carry `#error_no_message`), plus
    a new `WebformRankingInlineFormErrorsJavaScriptTest` (real browser,
    `inline_form_errors` enabled: a failed submission's error text
    appears exactly once, not once per radio).
    Both #68 and #69 landed together in one PR (two commits, one per
    issue, kept separate for independent review/revert despite both
    editing the same `buildMatrix()`/`preRenderWebformRanking()`
    methods) — bundled since both were found in the same browser-
    testing session and both are small, low-risk fixes.

23. **GitHub issue #59: matrix style's conditionally-hidden `<tr>`
    stayed in the DOM.** `buildMatrix()` applies a conditional item's
    `#states` to each *cell's* content individually (label div, each
    radio) — never to the row itself, since
    `Table::preRenderTable()`'s row-`#attributes`-to-`<tr>` merge runs
    during `#pre_render`, before `#states` processing adds
    `data-drupal-states` (the same timing constraint the file's own
    docblock already documents for why the label needed its own
    `container` wrapper). A hidden item left an empty `<tr>`/`<td>`
    shell visible. Not fixable server-side the same way as GitHub issue
    #57's fix (that's `form-element`-theme-wrapper-specific, not
    applicable to a `<tr>` inside `#type => 'table'`).
    Fixed client-side instead, in `webform_ranking.matrix.js`: a new
    `toggleRow()` sets the native `hidden` attribute on the row,
    driven by the *same* `'state:visible'` event already listened to
    for rank-exclusivity (`markTakenRanks()`, GitHub issue unrelated —
    see that function's own docblock) — no new event wiring needed,
    just acting on data already being observed.
    A real, easy-to-miss timing gap surfaced while implementing this,
    also fixed: `Drupal.behaviors.states` (core) and this element's own
    behavior both run during the same page-load attach pass, states.js
    first — so a row that's hidden *from the very first render* has
    already had its `'state:visible'` event fire and its cells hidden
    before this behavior's own listener existed to catch it. The
    existing `visible` tracking (used by `markTakenRanks()`) had this
    exact same latent gap already, just never surfaced as a visible bug
    since a stale "taken rank" mark is cosmetic. Fixed by seeding each
    row's initial `visible` state from `offsetParent === null` — the
    same technique `webform_ranking.dragdrop.js` already uses for its
    own position-numbering, for the identical reason. Covered by a
    dedicated test (`testConditionalItemRowHiddenOnInitialLoad()`,
    using its own webform with the trigger pre-filled) distinct from
    the live-transition test, specifically to exercise this path.
    Also added: `.webform-ranking-matrix tr[hidden] { display: none
    !important; }` in `css/webform_ranking.matrix.css` — `[hidden]` is
    a UA-stylesheet default, but table rows are a known cross-theme
    exception where an equal-specificity `tr { display: table-row }`
    rule declared later can win; explicit and `!important` so this
    doesn't depend on whichever theme this element happens to render
    under.
    Landed alongside GitHub issue #60 in the same branch/PR — both
    fixes react to the same `'state:visible'` event in the same file,
    and an earlier implementation pass of each (see #60's own
    write-up below) independently duplicated this exact same
    `offsetParent` seed; combined here so it's written once and shared
    by both `toggleRow()` and `updateRankColumns()`, not copy-pasted.

24. **GitHub issue #60: matrix rank columns didn't shrink as items were
    conditionally hidden.** Rank columns (1st, 2nd, ... + N/A) are
    built server-side from the *full configured* item count and never
    recomputed — already flagged as a known gap in `buildMatrix()`'s
    own docblock before this issue formalized it. Fixed entirely
    client-side, in `webform_ranking.matrix.js`'s new
    `updateRankColumns()`: hides (native `hidden` attribute, matching
    GitHub issue #59's row-hiding technique) a rank column's header
    *and* every row's cell at that position once fewer ranks than
    configured items are currently needed. N/A is never affected —
    it isn't a rank position tied to item count. Determines rank count
    and whether an N/A column exists from the *first row's own radio
    list* (`rank_1..rank_N` + optional `'na'`, always in the same
    order — `buildMatrix()` builds every row from the same configured
    rank count) rather than parsing header markup — matches this
    module's established structure-agnostic convention (see
    `getRadioGroups()`/`rowLabel()` in the same file).
    Driven by the *same* `'state:visible'` event already listened to
    for rank-exclusivity (`markTakenRanks()`) — no new event wiring,
    just one more reaction to data already being observed. Shares
    GitHub issue #59's `offsetParent`-seeded initial `visible` state
    (see that entry above) rather than duplicating its own copy, since
    both fixes landed together in the same branch/PR.
    Purely presentational, by design: rank-exclusivity and server-side
    "no gaps" validation
    (`WebformRankingConverter::matrixRanksAreSequential()`) already
    operate on the visible item set only — narrowing what's *offered*
    here doesn't change what's *valid*.
    A defensive `th[hidden]`/`td[hidden]` CSS rule (same rationale as
    GitHub issue #59's `tr[hidden]` rule) guards against a theme's own
    `th`/`td` display CSS outranking the `[hidden]` UA default.
    Test coverage: two new `WebformRankingMatrixJavaScriptTest` cases —
    a live transition (hide item C, confirm the 3rd column hides for
    the still-visible items specifically, N/A stays offered, then
    reveal again) and an initial-load case (own webform, trigger
    pre-filled) exercising the `offsetParent` seed path specifically.

25. **GitHub issue #74: validation message wording audit + a new
    `#sequential_ranks_error` override.** User-flagged: the matrix
    "no gaps" message ("ranks must be assigned starting from the top,
    with no gaps — a lower rank cannot be used unless every rank above
    it is also used") was too technical for an end user. Triage widened
    the scope beyond just that one message: this element's own 8
    `validateWebformRanking()` messages were internally inconsistent
    with themselves *and* with Webform core's own real convention.
    Confirmed against core (`web/modules/contrib/webform/src/Element/
    *.php`) — `WebformSignature.php`'s `'@title contains an invalid
    signature.'`, `WebformTermsOfService.php`'s `'@name field is
    required.'`, `WebformTime.php`'s `'%name must be a valid time.'` —
    core never colon-prefixes the field title; it's embedded
    grammatically into the sentence. 5 of this element's own 8
    messages had instead invented a `'@title: <message>'` colon-
    prefixed style found nowhere in core, while the other 3 already
    matched. Per user direction: rewrite **all 8** for plain language
    and fix the colon-prefix deviation on the 5 that had it, but only
    **two** get a new admin-overridable textfield — the sequential-
    ranks message (new `#sequential_ranks_error`) and the existing
    `#require_first_place_error` — the other three deviating messages
    (duplicate rank, ranked+N/A conflict, `#required_all`'s "every item
    must be ranked[/or marked N/A]") get wording fixes only. User
    explicitly chose this narrower scope over "every message becomes
    overridable" when asked.
    `#sequential_ranks_error` follows `#require_first_place_error`'s
    exact established pattern: `defineDefaultProperties()` default
    `''`, added to `defineTranslatableProperties()`, checked via
    `!empty()` in `validateWebformRanking()` (not `??` — the default is
    `''`, not `NULL`, so `??` would treat an admin's not-yet-set field
    as "customized" and never fall back to the translated default).
    Its admin field is deliberately left ungated by `#states` (unlike
    `na_label`/`require_first_place_error`, both gated on their own
    toggle) even though the check it overrides is matrix-only — user's
    own call: the extra `#states` complexity isn't worth it for a field
    that simply does nothing when irrelevant, same as `#required_all`
    itself applying unconditionally to both display styles.
    `#require_first_place_error`'s own admin field also changed:
    label "Require 1st place message" → "Require 1st place error
    message"; description reworded to the "appears when there is an
    error in validation for..." framing, which the new field's
    description matches for consistency between the two.
    Wording-change blast radius was wider than just
    `validateWebformRanking()` itself — audited the whole test suite
    (not just the obvious kernel test) and found two
    `FunctionalJavascript` tests from earlier PRs
    (`WebformRankingErrorDisplayJavaScriptTest`,
    `WebformRankingInlineFormErrorsJavaScriptTest`) asserting on the
    literal old `'starting from the top'` substring, plus several
    `WebformRankingValidationKernelTest` substring assertions
    (`'every item must be ranked'`, `'1st place'`) that no longer
    matched the new wording — all updated, not just the obviously-
    affected message-content tests. Two new kernel tests added
    specifically for the sequential-ranks check, previously untested
    at the kernel level at all (only indirect coverage via
    `WebformRankingConditionalItemTest`/the FunctionalJavascript
    suite): one for the new default wording, one for the
    `#sequential_ranks_error` override, both using a directly-
    fabricated `#_matrix_raw_input` (same forged-input technique
    `testDuplicateRankInForgedValueIsRejected()` already established)
    rather than driving the real matrix/converter path.

26. **GitHub issue #65 (redo of #13): a visual condition-rows builder
    for per-item states, matching the real element-level "Conditional
    logic" tab's own look.** A first attempt at #13 (closed PR #64)
    built a simplified custom 4-field picker instead of replicating the
    real builder's UI, and — the more consequential mistake — introduced
    a new `condition_group` wrapper around the whole field, moving the
    `webform-ranking-item-states-wrapper` class `items_admin.js` uses to
    find/relocate content into the dialog up one DOM level from where it
    always lived, incidentally altering the "Conditions" trigger
    button's own markup. Redone from scratch on a new branch after
    review flagged both problems, with a tightened spec (`docs/
    issue-13-redo-plan.md`, later archived) confirmed before
    implementation started.
    **Key design choice:** the builder writes directly into the
    *existing* `states` YAML `webform_codemirror` field — no new form
    field at all. Confirmed first that `#decode_value => TRUE` already
    accepts any valid `#states` shape (multi-condition, AND/OR/XOR)
    with no PHP-side restriction, so this was purely a UI gap. The
    `states` field's own `#type`/`#mode`/`#decode_value`/
    `#wrapper_attributes`/position in `#element` are byte-identical to
    the pre-#13 baseline — the only PHP addition is computing
    `decomposeItemStatesToConditions()` (a generalized version of the
    first attempt's single-condition decompose, now also handling
    AND/OR/XOR multi-condition shapes) and passing the result to JS via
    `drupalSettings`, keyed by item `value` (the stable identity used
    throughout this codebase). No corresponding `compose*()` needed
    server-side: the builder keeps the textarea's own YAML text in sync
    on every change, so the existing, unmodified decode-on-submit path
    handles it exactly as it does for hand-typed YAML.
    **UI fidelity, per explicit direction during review** (not the
    original scoped-down plan): a `<fieldset>` wrapping a real
    `<table class="webform-states-table">` with State/Element/
    Trigger-Value columns, the state row's combining-operator select
    always visible ("if [All/Any/One] of the following is met:",
    unconditionally, matching the real widget — an earlier draft of
    this hid it until 2+ conditions existed, caught and corrected from
    live feedback), the full curated state list (`self::
    PICKER_STATE_KEYS`: Visible/Hidden/Visible (Slide)/Hidden (Slide)/
    Enabled/Disabled/Required/Optional — a subset of `WebformElementStates
    ::getStateOptions()`'s full list, excluding states aimed at form
    *inputs* rather than item visibility), and the "Edit source" button
    label matching the real widget's own text exactly. Only
    `visible`/`invisible`(-slide) actually affect item inclusion
    (`WebformRankingVisibilityResolver` still only acts on those); the
    other four are accepted/stored like any other `#states` value,
    matching the real widget's own flexibility, with a warning note
    shown when selected rather than hidden outright.
    **Markup classes matched exactly, not approximately**, per further
    review feedback — Drupal's admin theme styles form elements and
    fieldsets via specific classes, not the bare tags, so getting these
    exactly right (confirmed each time by inspecting this same admin
    form's real, server-rendered markup, not guessed) is what actually
    produces matching visual treatment rather than unstyled native
    controls: every hand-built `<select>`/`<input>` carries the same
    classes Drupal's own form rendering adds (`form-element` on both;
    `form-select`/`form-element--type-select` on selects;
    `form-text`/`form-element--type-text` on text inputs — via two new
    small helpers, `createSelectElement()`/`createTextInputElement()`,
    rather than repeating the class strings at each of the 5 call
    sites). The `<fieldset>`/`<legend>` similarly copy the real
    `#type => fieldset` element's own classes verbatim
    (`js-webform-type-fieldset webform-type-fieldset fieldset
    js-form-item form-item js-form-wrapper form-wrapper` on the
    fieldset; `fieldset__legend fieldset__legend--visible` on the
    legend, with its text wrapped in a `<span class="fieldset__label">`
    — all three confirmed against the element-level "Conditional
    logic" tab's own rendered fieldset, `#edit-conditional-logic`),
    including the `<div class="fieldset__wrapper">` real fieldsets use
    to wrap their content (everything but the legend) — not explicitly
    requested, but added once found: it's what the admin theme's
    fieldset CSS actually targets for padding/spacing, not the
    `<fieldset>` element directly, so omitting it would have left the
    class-matching incomplete.
    **Two real bugs found via live browser testing, not guessed:**
    - *jQuery UI autocomplete double-init crash.* The real widget's own
      server-rendered markup puts the class `webform-states-table--condition`
      on both a condition `<tr>` AND its own inner `<td>` wrapping
      trigger+value (confirmed by reading `WebformElementStates
      ::buildConditionRow()` directly) — reproducing this exactly caused
      `webform/webform.element.states`'s behavior (which scopes itself
      via `.find()` from any matching element) to initialize twice on
      the same value input, the second pass corrupting the widget
      instance the first had already set up:
      `TypeError: this.source is not a function` inside jQuery UI's
      autocomplete, breaking every subsequent selector change. Fixed by
      keeping the class on the `<tr>` only — the row is the only match
      that has selector+trigger+value all as `.find()`-reachable
      descendants anyway, since selector is a sibling `<td>` of the
      trigger/value cell, not nested inside it.
    - *Test-environment-only: jQuery 4's `.trigger('change')` never
      reached plain `addEventListener('change', ...)` listeners at
      all*, confirmed via an isolated scratch-element check
      (`jQuery(el).val(x).trigger('change')` on a bare `<select>` with a
      manually-attached native listener: zero fires). Not a production
      bug — a real user's actual browser interaction is a genuine native
      event either way — but it meant every `FunctionalJavascript` test
      helper that used jQuery's `.trigger()` to simulate a field change
      silently no-opped. Fixed in the test file only, dispatching
      `new Event('change', {bubbles: true})` directly instead, matching
      what the pre-existing (already-working) YAML-field test helpers
      did all along.
    Test coverage: `WebformRankingPluginTest` gained
    `decomposeItemStatesToConditions()` cases for every trigger type and
    AND/OR/XOR shapes, plus confirmation that genuinely unsupported
    shapes (multiple states, mixed operators, a trailing operator, an
    unrecognized trigger) correctly return `NULL` rather than corrupting
    data. `WebformRankingItemsAdminJavaScriptTest` gained: decomposed-
    condition display, set-via-builder-and-persist (proving the
    client-side YAML emitter is correct, not just "looks right"),
    multi-condition OR persistence, add/remove row chrome, the
    too-complex-condition YAML-view fallback, and the Edit
    source/back-to-builder toggle — on top of the three pre-existing
    dialog-mechanics tests (trigger button, Clear condition, submission
    persistence), which needed no changes and serve as the regression
    check for the exact thing the first attempt broke.
    **Two follow-up rounds of review, after the above landed:**
    - *Exact markup, not approximate.* Every hand-built `<select>`/
      `<input>` now carries the same classes Drupal's own form
      rendering adds (`form-element`; `form-select`/
      `form-element--type-select` on selects; `form-text`/
      `form-element--type-text` on text inputs — via two small helpers,
      `createSelectElement()`/`createTextInputElement()`, rather than
      repeating the strings at each of the 5 call sites), and the
      `<fieldset>`/`<legend>` copy the real `#type => fieldset`
      element's own classes verbatim, including the
      `<div class="fieldset__wrapper">` real fieldsets use to wrap
      their content — the admin theme's fieldset CSS targets that div
      for padding/spacing, not the `<fieldset>` element directly. Every
      class list confirmed against this same admin form's real,
      server-rendered markup (`#edit-conditional-logic` for the
      fieldset), not guessed. The combining-operator select was also
      corrected here to be unconditionally visible (an earlier pass
      hid it until 2+ conditions existed).
    - *Per-row +/- icon buttons*, replacing a single bottom "Add
      another condition" text button — the real gap this closed: with
      the bottom-button-only design, an item with exactly one
      already-saved condition had no way to remove it at all (the
      remove affordance only appeared once 2+ rows existed). Matches
      `WebformElementStates::buildOperations()`'s own plus.svg/
      minus.svg icon buttons, always available on every row (no
      "at least one row" floor — same as the real widget, whose own
      remove button is only ever omitted when `#multiple` is `FALSE`,
      never the case here). Deliberately **not** `<input type="image">`
      like the real widget, though: that's inherently a submit
      control, and clicking one inside this dialog's enclosing admin
      `<form>` would submit the whole element config form unless every
      handler remembered `preventDefault()` — one missed line from an
      accidental early "Save" mid-edit. Used a plain
      `<button type="button">` instead (never submits, regardless),
      styled via a new `css/webform_ranking.items_admin.css` to match
      the real icon buttons' own CSS
      (`webform.element.states.css`'s `input[type="image"]` rules,
      reapplied to the button). The icon `src` URL itself is built from
      `\Drupal::service('extension.list.module')->getPath('webform')`
      — the same call the real widget's own PHP makes — passed via
      `drupalSettings` rather than guessing a `modules/contrib/webform`-
      shaped path client-side, since that layout isn't guaranteed for
      every install. "-" on the sole remaining row resets it to blank
      rather than deleting it (so the table never ends up with zero
      rows and no way back in without reopening the dialog) —
      functionally identical to true removal either way, since
      `emitYaml()` already skips any condition with no selector chosen.

27. **PR #66 code review response: 6 fixes to the per-item condition
    builder (issue #65), 2 confirmed real bugs and 4 smaller ones,
    verified against actual vendored source rather than taken on faith
    from the review's own claims.**
    - **State-select/Required-checkbox collision (confirmed).** The
      state picker's `<select>` originally reused Webform core's own
      `webform-states-table--state` class for visual parity — which
      also happens to be core's own *unscoped* jQuery selector in
      `webform.element.states.js`'s `toggleRequiredCheckbox()`, run
      against the whole document (not just this dialog) on every
      `Drupal.attachBehaviors()` call. Since `PICKER_STATE_KEYS` allows
      Required/Optional as valid per-item states, and the builder DOM is
      built eagerly for every item at page load, simply having an item
      saved with a Required/Optional state force-checked and disabled
      this element's entirely unrelated top-level "Required" property on
      every page load. Fixed by renaming to module-scoped classes
      (`webform-ranking-item-condition-state-row`/`-cell`) with the same
      cosmetic CSS reapplied under the new names — the per-row *empty*
      state placeholder `<td>` (column alignment only, no `<select>`
      inside it, matching the real widget's own row markup) was
      deliberately left on the original class name, since it has no
      `<select>` descendant and so can't trigger the same collision.
    - **Duplicate-selector-under-"All" data loss (confirmed).** Two
      condition rows targeting the same Element combined with "All"
      serialize to a YAML flow mapping keyed by selector twice —
      confirmed against the vendored `vendor/symfony/yaml/Parser.php`
      that a duplicate mapping key silently keeps only the last
      occurrence. Investigated whether an indexed-list-with-literal-
      `'and'` shape (as one review pass suggested, "mirroring" `'or'`/
      `'xor'`) could losslessly represent this instead — traced Drupal
      core's actual client-side evaluator (`web/core/misc/states.js`'s
      `verifyConstraints()`) and confirmed an indexed array only ever
      means OR (default) or XOR (if the literal `'xor'` token is
      present); there's no `'and'` token semantics at all, so a literal
      `'and'` entry would silently evaluate as an inert no-op, not an
      AND — that "fix" would have been *worse* than the bug (wrong
      results at runtime, not just one dropped condition). Re-checked
      core's own `WebformElementStates::convertElementValueToFormApiStates()`:
      for this exact case it doesn't attempt an alternate shape either —
      it raises a hard validation error. No lossless representation
      exists in Drupal's own #states model for "two AND conditions, same
      selector" at all (the correct native way is a single `between`/
      `!between` trigger on one row). Fixed by mirroring core's own
      choice — detect and warn, not invent a shape — via a new
      `duplicateSelectorWarning` message (same `messages messages--
      warning` pattern as the existing `stateWarning`), checked in
      `updateDuplicateSelectorWarning()` on every builder change.
    - **Reclassified, not fixed: "parser rejects valid indexed-'and'
      shape."** The review flagged `decomposeItemStatesToConditions()`
      rejecting a literal `'and'` operator in the indexed-list branch as
      a bug. Per the states.js investigation above, that shape isn't
      actually valid/meaningful #states syntax at all — nothing produces
      it, and nothing correctly consumes it at runtime. Left unchanged.
    - **AJAX row-add reverting unsaved condition edits: deferred to
      #79**, not fixed here. Traced to `WebformUiElementFormBase`
      caching the saved entity's `element_properties` across
      `#webform_multiple` AJAX rebuilds (confirmed via the vendored
      webform module) — a real bug, but one whose fix touches base
      Webform UI class behavior rather than this module's own code, and
      is riskier/bigger than the other fixes here. Filed as its own
      issue with the full root-cause trace rather than attempting a
      shallow fix under this branch's time budget.
    - **`trim()` mismatch between PHP's and JS's lookup keys.** PHP's
      `$conditions_by_value` is keyed by `trim($item['value'] ?? '')`;
      the JS read the live Value input verbatim. Fixed by trimming
      client-side too. Regression-tested with a fixture item whose value
      has literal surrounding whitespace (set directly on the config
      entity in the test, bypassing the settings-form-only save-time
      value validation from issue #21).
    - **"Clear condition" force-switching away from YAML view.** The new
      `builder.clear()` unconditionally called `showBuilder()`, yanking
      a user who had "Edit source" open back to the builder view even
      though clearing has nothing to do with which view should be
      showing. Fixed by dropping that call — the builder's own rows
      still reset underneath, correct whenever the user does switch back
      themselves.
    - **Test-coverage gap restored.** PR #66's own fixture change (item
      `b`'s selector switched from a deliberately out-of-list value to a
      real one, for an unrelated reason) had silently dropped the only
      coverage of `createConditionRow()`'s "stray option" fallback for a
      saved-but-unlisted selector. Restored as its own dedicated fixture
      item/test rather than re-overloading item `b`.
    - **Stale docs.** `DEVELOPMENT.md` and this file's own Known Gaps
      section both claimed this module's CSS is "structural/layout
      only" — no longer true once `webform_ranking.items_admin.css`
      (genuine visual design, mirroring core's own icon-button styling)
      shipped with #65. Both updated to note the one exception.
    Deliberately NOT addressed in this same branch (left for a separate
    pass, per explicit scoping): the review's reuse/efficiency/
    simplification findings (hand-duplicated trigger-classification
    lists between JS and PHP, the icon-button CSS copy-pasted instead of
    overriding shared core classes, per-keystroke full YAML/CodeMirror
    rebuilds, eager per-item DOM construction at page load, and a few
    smaller code-duplication items) — real cleanup opportunities, but
    none are correctness bugs, and bundling them in would have made this
    already-large review-response diff much harder to review in one
    sitting.

28. **GitHub issue #79: per-item condition builder losing an unsaved
    edit across an unrelated `#webform_multiple` AJAX rebuild.** Deferred
    out of #66's own review-response branch as "bigger/riskier" (see
    entry 27) — traced properly here instead of guessing. Root cause:
    `WebformRanking::form()` computed `$conditions_by_value` (the
    server-side decomposition JS reads via `drupalSettings` to populate
    the builder) from `$form_state->get('element_properties')['items']`
    — a snapshot of the *saved* entity, taken once when the form object
    is first built (`WebformElementBase::buildConfigurationForm()`) and
    never refreshed, since `#webform_multiple`'s own add/remove-row AJAX
    callback (`WebformMultiple::ajaxCallback()`) rebuilds and returns the
    whole items table without rebuilding that cached form-object state.
    Confirmed empirically, not by reading source alone: instrumented
    `form()` with a temporary `\Drupal::logger()` dump of both
    `$form_state->getUserInput()` and `$form_state->getValues()`,
    reproduced the exact failure scenario live (DDEV + Playwright:
    open an item's dialog, edit its condition, close without saving,
    click the items table's own "Add" button, inspect the resulting
    watchdog entries) — confirmed `$form_state->getValues()` (unlike the
    cached `element_properties`) is *already* fully populated with the
    live, unsaved submission during exactly this AJAX rebuild, nested at
    `['items']['items'][$delta]` (matching `#webform_multiple`'s own
    `#tree` shape), and empty on a genuine first page load (no
    submission yet) — meaning it can be preferred over the stale
    snapshot with no separate "is this an AJAX rebuild" branch needed;
    checking `is_array($form_state->getValue(['items', 'items']))` alone
    correctly selects the live source when present and falls back to the
    saved-entity snapshot otherwise. Fixed with a 2-line source swap,
    verified live in the browser (the exact repro scenario above now
    correctly keeps the unsaved edit after the AJAX rebuild) before
    writing the automated regression test. This generalizes beyond the
    reported "Add row" case to any AJAX rebuild of this form — the fix
    doesn't special-case which trigger caused the rebuild.

29. **PR #82 code review response: 2 confirmed bugs + a residual
    class-collision landmine.** #66/#65 merged into `feature/states-ui`;
    a fresh `/code-review` run on the resulting `feature/states-ui` →
    `dev` PR (8 parallel finder agents) found 11 findings. Two were
    CONFIRMED by independently re-verifying against the actual vendored
    source rather than trusting the finder agents' own claims — one of
    which corrected a claim *this same log* had made in entry 27.
    - **Duplicate-selector-under-"All" doesn't silently lose data — it
      throws.** Entry 27 documented (based on checking `vendor/symfony/
      yaml/Parser.php`) that a duplicate YAML mapping key "silently
      keeps only the last occurrence." Wrong parser class: `emitYaml()`'s
      AND branch produces *flow-style* YAML (`{sel: {...}, sel: {...}}`),
      parsed by `Inline.php`, not `Parser.php` — and `Inline.php`
      unconditionally throws `ParseException` on a duplicate key, no
      silent/deprecated fallback (that path only exists in `Parser.php`'s
      block-style handling). Confirmed directly against the vendored
      source before believing it. Two compounding consequences: at
      normal Save time, `WebformCodeMirror::validateYaml()` does catch
      it, but surfaces a raw, unhelpful Symfony error instead of the
      "only the last one will be saved" UX the warning message and
      CHANGELOG both promised; during a `#webform_multiple` AJAX rebuild
      (the #79 fix's own new code path, `form()` line ~183), the same
      exception was **uncaught**, crashing the whole request instead of
      falling back to the YAML view for that one item. Fixed on both
      sides: `updateDuplicateSelectorWarning()` now returns whether it's
      showing, and `onBuilderChange()` withholds the `writeYamlField()`
      call entirely while true — the field simply keeps its last valid
      value, so the throwing YAML string is never constructed in the
      first place, not just warned about. `form()`'s decode call is now
      wrapped in try/catch (`InvalidDataTypeException`, the exception
      core's own `Yaml::decode()` wraps Symfony's `ParseException` in),
      falling back to "not decomposable" the same as any other
      non-array `$states` value — general-purpose, not specific to the
      duplicate-selector case, since any hand-typed malformed YAML in
      "Edit source" reaches this same live-decode path.
    - **Condition-row autocomplete never actually initialized, for any
      row, ever.** `addConditionRow()`/the "+" button handler called
      `Drupal.attachBehaviors(conditionRow)`/`Drupal.attachBehaviors(
      newRow)`, passing the just-created row *itself* as context. Per
      `@drupal/once`'s own implementation, `context.querySelectorAll(
      selector)` structurally can never match `context` itself, only
      descendants — and `.webform-states-table--condition` (what
      `webform.element.states.js`'s behavior looks for, to wire up value
      autocomplete) is set on that same row, not a descendant of it, per
      this module's own deliberate double-init-crash fix (entry 15/#3).
      100% reproducible, not a load-order coincidence. Fixed by
      attaching on `tbody` (an ancestor of every row, old and new)
      instead — `once()`'s own id-tracking correctly re-scans only
      not-yet-processed rows, no double-attachment risk. Verified via
      jQuery UI's own `ui-autocomplete-input` marker class, added to an
      element on successful widget init — a newly-added row's Value
      field now genuinely carries it, where before this fix it never
      did.
    - **Residual same-named-class landmine closed off.** Entry 27's
      Required-checkbox fix renamed the *state row's* select wrapper
      away from core's collision-prone `webform-states-table--state`
      class, but every condition *row* still built an empty placeholder
      `<td class="webform-states-table--state">` (no `<select>` inside,
      column-alignment only) using that same exact string — harmless
      today, but one future edit away from silently reintroducing the
      identical collision if a `<select>` ever landed inside it.
      Renamed to a module-scoped class; purely cosmetic, no CSS/test
      changes needed since nothing targeted this specific empty cell.
    Deliberately NOT addressed in this branch (filed as #83/#84/#85
    instead, per explicit scope decision): a cluster of "no shared
    source of truth" duplication findings (trigger/state classification
    lists, PHP's `#states` parser reimplementing core's own conversion
    logic, the hand-rolled YAML emitter), a cluster of JS efficiency
    findings (eager per-item DOM construction, per-keystroke non-
    debounced rebuilds, redundant `Drupal.attachBehaviors()` calls,
    duplicate DOM traversal, double-firing change events), and two small
    documentation/robustness notes (the rejected literal-`'and'` shape
    still has no in-code explanation despite entry 27's own investigation
    — a genuine gap this project's own `feedback_flag_deferred_design_
    decisions` convention should have caught; `form()`'s new implicit
    `getFormObject()->getWebform()` precondition).

30. **GitHub issues #83/#84/#85: condition-builder cleanup pass —
    everything deferred out of entry 29, tackled together.**
    - **#85 (docs/robustness), both items fixed as suggested**: the
      rejected literal-`'and'` shape in `decomposeItemStatesToConditions()`
      now has an inline comment explaining the `states.js` investigation
      that ruled it out, pointing back to entry 27 for the full trace —
      exactly the gap #85 itself was filed to close. `form()`'s new
      `getFormObject()->getWebform()` precondition got an inline comment
      noting it's satisfied by every real caller today but isn't
      defended against, since no call path that would violate it
      currently exists.
    - **#84 (efficiency), all 7 items addressed**: `initConditionBuilder()`
      is now built lazily on the trigger button's first click (memoized)
      instead of eagerly for every item at `attach()` time — the single
      biggest item, since it's O(items) DOM construction down to
      O(items actually opened). Reading `drupalSettings` at click time
      instead of attach time is also strictly more correct, not just
      lazier: Drupal's AJAX framework merges fresh settings into the
      global object on every response, so a later read only ever sees
      more current data. `updateDuplicateSelectorWarning()` and
      `emitYaml()` now share one `getConditionRowsData()` traversal
      instead of two independent ones. The Value input's `input`
      listener (real keystrokes) is now debounced 200ms into
      `onBuilderChange()`; `change` (programmatic/test-driven sets)
      stays immediate. `createSelectorSelect()`/`createTriggerSelect()`
      build their `<option>`/`<optgroup>` DOM once per item and
      `cloneNode(true)` per row instead of re-walking the same settings
      object for every row. `writeYamlField()` skips its manual
      `dispatchEvent()` calls on the CodeMirror path — traced
      `webform.element.codemirror.js`'s own source first to confirm
      `.save()` itself fires nothing, and the one listener the manual
      dispatch does reach (`$input.on('change', ...)`) only re-syncs the
      editor to the identical value already set via the CodeMirror API
      two lines above, a genuine no-op in that branch specifically (not
      a blind "seems redundant" removal). `addOption()`/`addOptionTo()`
      merged into one (they were byte-identical). `renderConditions()`'s
      initial bulk-render now does one `Drupal.attachBehaviors(tbody)`
      after appending every saved-condition row, instead of one call per
      row via a since-removed `addConditionRow()` wrapper that turned
      out to have exactly one caller.
    - **#83 (shared source of truth), 2 of 4 items fixed, 2 documented
      as deliberately not changed**: `NESTED_TRIGGER_KEYS`/
      `NO_VALUE_TRIGGER_KEYS`/`VISIBILITY_STATE_KEYS` are now
      `WebformRanking` class constants, sent to `items_admin.js` via
      `drupalSettings.webformRankingItemsAdmin` (three new keys) instead
      of a second hand-typed copy in JS — `decomposeCondition()` also
      switched to reading `self::NESTED_TRIGGER_KEYS`/
      `self::NO_VALUE_TRIGGER_KEYS` instead of a local variable and an
      inline array literal. `VISIBILITY_STATE_KEYS` deliberately does
      **not** attempt to unify with `WebformRankingVisibilityResolver::
      isVisible()`'s own separate `['visible', 'invisible']` check —
      that method strips a leading `!` and any `-slide`/other suffix
      before comparing the base, which already generalizes to future
      suffix variants without a list update; forcing both to share one
      literal list would mean either weakening the resolver's own
      general-purpose parsing to match a flat enumeration, or replacing
      a simple UI-dropdown enumeration with runtime string-parsing logic
      it doesn't need — both worse than documenting (now done, on both
      sides) that the two express the same semantic set through
      different, equally intentional means. The other two #83 findings
      (PHP's `decomposeItemStatesToConditions()` reimplementing core's
      own `#states`-array conversion; the hand-rolled JS YAML emitter
      not sharing logic with `WebformYaml::encode()`) are left as-is per
      the issue's own suggested next steps — core's equivalent methods
      are `protected static` (not callable without a core patch), and
      no vendored client-side YAML encoder exists to swap the emitter
      for (confirmed: Webform only ships CodeMirror's YAML *mode*, a
      syntax highlighter, not a serializer). Real gaps, correctly not
      urgent.
    3 new regression tests added for the #83 settings-move specifically
    (nested-trigger persistence, no-value-trigger field hiding,
    non-visibility-state warning) — closing a pre-existing test gap this
    refactor's own risk surfaced: nothing previously exercised any of
    these three behaviors through the builder UI at all, only via
    `decomposeCondition()`'s own PHP-level Kernel tests.

    **Correction (see entry 31): the "protected static, not callable
    without a core patch" claim two paragraphs up, about
    `decomposeItemStatesToConditions()`/`decomposeCondition()` not
    reusing core's own `#states`-array conversion, is only half right.**
    The methods genuinely are `protected static` — but that only blocks
    an unrelated class from calling them directly, not a subclass. A
    small adapter class extending `\Drupal\webform\Element\
    WebformElementStates` specifically (not this module's own class
    hierarchy) could call them without any core patch. Filed as GitHub
    issue #91 (a documentation-accuracy issue, not a functional bug —
    this reimplementation isn't broken, just not confirmed-impossible to
    avoid); left as a documented future option rather than implemented
    here, to keep entry 31's own fix appropriately scoped.

31. **GitHub issues #88/#91/#92/#93/#94: second-wave cleanup pass, from
    the final `/code-review` on PR #82 itself** (post-#87 merge review;
    all filed as lower-priority/unmilestoned — see project memory for the
    full review context). Tackled together as one bundled, low-risk pass.
    - **#88 (hand-typed YAML skips builder safety checks), both problems
      addressed without a client-side YAML parser** (none is vendored —
      see #83's own note on that gap, still true): "Back to condition
      builder" now compares the YAML field's current text against what
      the builder itself last wrote there, and shows a non-blocking
      warning (`.webform-ranking-item-condition-stale-warning`) when they
      differ — the very next builder interaction still overwrites the
      hand-typed text exactly as before, but the admin now sees it
      coming instead of it happening silently. "Edit source" also gained
      a plain-language note up front
      (`.webform-ranking-item-edit-source-note`) naming both footguns
      (the same-Element-under-"All" restriction, and #92's `min:max`
      format) before an admin can run into either one. A full re-parse
      of arbitrary hand-typed YAML back into builder rows was considered
      and deliberately not attempted — hand-rolling a YAML parser just
      for this warning would be a much larger, riskier change than the
      problem (a lower-priority UX rough edge, not a data-integrity bug)
      warrants.
    - **#91 (inaccurate reuse-impossibility claim)**: this entry's own
      correction above, plus the same correction on entry 30. The
      suggested adapter-class reuse itself is left as a documented future
      option, not implemented now — a separate, larger, riskier
      refactor than "correct the paperwork," deliberately out of scope
      for this bundled pass.
    - **#92 (no format hint for "between")**: the condition builder's
      Value input's placeholder now reads `e.g. 1:5 (min:max)` for
      `between`/`!between` specifically (`BETWEEN_TRIGGERS` in
      items_admin.js), the generic `Enter value…` for every other
      trigger — set inside `updateValueFieldVisibility()`, which already
      ran on every trigger change.
    - **#93 (5 small cleanup items), all 5 done**: the no-op
      `Drupal.attachBehaviors(wrapper)` call (nothing in `wrapper` needed
      it at that point — the real one, on `tbody` inside
      `renderConditions()`, already covered every condition row) is
      removed outright. A condition row's "blank" defaults
      (`BLANK_CONDITION`) are now defined once and shared by
      `createConditionRow()` and `resetConditionRow()`. The
      CodeMirror-instance lookup (`yamlField.nextElementSibling.
      CodeMirror`), previously repeated at three call sites, is now one
      shared `getCodeMirrorInstance()` helper. `WebformRanking::form()`'s
      state-options flattening now uses core's own
      `\Drupal\Core\Form\OptGroup::flattenOptions()` instead of a
      hand-rolled nested loop; `decomposeItemStatesToConditions()`'s
      "is this an indexed list" check now uses PHP's own
      `array_is_list()` instead of an equivalent `range()` comparison.
      `renderConditions()` no longer re-queries the DOM for data it had
      literally just built the rows from a few lines above — it reuses
      the same `decomposed.conditions`/`[]` array for
      `updateDuplicateSelectorWarning()` instead.
    - **#94 (test coverage gap)**: a new fixture item ('g', using this
      element's own "Allow abstaining" checkbox selector — the exact
      selector item 'b' used before PR #66 switched it to a real, listed
      one) and a dedicated test
      (`testConditionBuilderPreservesRealButUnlistedSelector`) restore
      coverage for "a real DOM input on this form that just isn't a
      valid choice for this feature," distinct from item 'f''s existing
      "selector doesn't exist at all" coverage.
    5 new regression tests added in total (2 for #88, 1 for #92, 1 for
    #94, plus the #88 safety-note visibility check) — #93's items are
    pure refactors with no behavior change, so no new tests needed there
    beyond the full existing suite passing unchanged.
32. **GitHub issue #102: matrix `#required_all`'s conditional-row fix
    (entry 21/#68) itself crashed submission**, found during 0.3.0
    manual release testing. Root cause: mirroring an item's own
    visible/invisible condition into a `required`/`optional` `#states`
    key made Webform core's `WebformSubmissionConditionsValidator`
    resolve a Webform element plugin for the item's bare `radio`/
    `container` sub-elements — none exists, so it fell back to a
    generic placeholder plugin whose `getFormElementClassDefinition()`
    call then threw `PluginNotFoundException` (that placeholder's own ID
    isn't a real render element type). Fixed by removing the mirror
    entirely — a conditional row's native `required` attribute is now
    permanently withheld instead of live-toggled; `required`/`optional`
    are also no longer offered as a per-item condition state at all
    (an item's required-ness was never meant to be independently
    configurable — it's derived from `#required_all`), and hand-typing
    either into the raw YAML "Edit source" field is now rejected at
    save time. See `docs/adr/0018-remove-required-optional-states-mirror.md`
    (supersedes the relevant part of ADR-0007).
33. **GitHub issue #104: a hidden matrix item's stale rank could
    silently collide with another item's rank on reappearance**, found
    while manually verifying #102's fix. Root cause:
    `WebformRankingConverter::matrixToCanonical()` keys an intermediate
    array on rank number, so a genuine duplicate silently collides —
    the later-processed item wins, the other is dropped entirely, not
    flagged. The resulting "missing" item then tripped `#required_all`'s
    check with a misleading message, since neither of the two checks
    that looked like they'd catch this (`count($values) !==
    count(array_unique($values))`, and `matrixRanksAreSequential()`)
    actually can — both operate after or around the point where the
    duplicate is already gone. Fixed on both sides, confirmed
    deliberately as both-not-either: client-side, `matrix.js` now
    clears an item's rank/N/A selection the moment it's hidden (not
    deferred until it's shown again), so the collision is no longer
    reachable through normal interaction; server-side, a new
    `WebformRankingConverter::matrixRanksHaveNoDuplicates()` reads the
    raw per-item input directly (before the collision can hide it) and
    rejects with a message naming the actual problem — inserted before
    the `#required_all` check in `validateWebformRanking()`, since
    `FormState::setErrorByName()`'s first-error-wins semantics make
    that ordering the actual fix for the misleading message, confirmed
    by reading core directly rather than assumed. Also corrected the
    same false "server-side validation already discards a hidden
    item's stale selection" claim in ADR-0012. See
    `docs/adr/0019-matrix-duplicate-rank-detection.md`.
34. **GitHub issue #108: drag/drop `#required_all`'s UI relevance and
    hidden-item state handling.** Two fixes. First, `#required_all` is a
    structural no-op for drag/drop (every item always lands ranked or
    N/A'd — there's no "unaccounted for" state), so it's now hidden via
    `#states` when `ranking_style` isn't `matrix` — deliberately
    UI-only, per the issue's own follow-up comment, so the stored value
    survives a round trip through drag/drop and back to matrix. Second,
    and more involved: `webform_ranking.dragdrop.js` had no per-item
    reaction to an item's own conditional visibility, only a page-wide
    listener that re-numbered visible positions. This left the per-item
    rank *echo* input (ADR-0008 — the channel other `#states` conditions
    and `webform_computed_twig` live recomputes watch) holding a hidden
    item's stale value, genuinely observable downstream (confirmed via
    the user's own worked example: a "banana color" field shown when
    "Banana ranked 1st" incorrectly stayed matched after Banana was
    hidden). An initial fix attempt marked a hidden item's echo `'na'`
    — rejected on review: `'na'` is a real, respondent-facing value, and
    borrowing it as a "hidden" stand-in is meaningless when `#allow_na`
    is off, and doesn't produce the coalescing actually wanted. Correct
    fix: `sync()` now computes `order`/`na` from only currently-visible
    items and blanks (not `'na'`s) a hidden item's own echo — a hidden
    item is excluded from the dataset entirely, coalescing the rest, as
    a side effect of the same filter `renumber()`/`moveItem()` already
    used. A new per-item `state:visible` listener replaces the old
    page-wide one: hide just calls `sync()`; reveal calls the existing
    `setItemNa(item, false)` (already implements "re-enter at the end
    of the ranked stack," reused rather than reimplemented). First test
    run against this exposed a second, genuinely surprising bug: the
    `state:visible` event fires *before* states.js's own DOM-hiding
    effect actually completes, confirmed by directly instrumenting the
    handler — `offsetParent` was still non-null inside the very handler
    reporting the hide. A hide-time `sync()` call, running synchronously
    inside that event, therefore raced the animation and saw a stale
    "still visible" DOM, so nothing changed at all. Fixed by tracking
    visibility in a `knownVisible` value-keyed map updated directly from
    each event's own `e.value` rather than re-derived from the DOM at
    call time — the same approach `matrix.js`'s own `visible` map
    already uses, for the identical reason. A second, separate bug
    surfaced during manual testing after this fix landed: a hidden
    item's N/A checkbox, when revealed, visually stayed checked even
    though the underlying data correctly reset to "ranked, not N/A."
    Root cause: Webform core's own `webform.states.js` independently
    backs up every `:input` inside a conditionally-hidden webform
    element and restores its *pre-hide* value on reveal, via a
    document-level `state:visible` handler that (bound on an ancestor)
    runs synchronously right after this module's own item-level
    handler within the same event dispatch — silently overwriting the
    `checked = false` this fix had just set. Fixed by deferring that
    one assignment via `setTimeout(fn, 0)`, so Webform's own restore
    runs first and this fix's outcome is what's left standing. See
    `docs/adr/0020-dragdrop-required-all-visibility-and-hidden-item-state.md`.
35. **GitHub issue #10 ("Visual design pass for matrix and drag/drop
    displays") split into six focused sub-issues** (#115-#120) — it had
    accumulated several genuinely independent pieces (theming infra,
    drag/drop icons, uniform control heights, matrix N/A/taken-radio
    styling, admin-configurable options, and a follow-up comment's
    responsive-collapse idea that explicitly left open whether it
    belonged in #10 or its own issue). #10 closed with cross-references
    to the six; first one tackled was #115 (responsive matrix collapse).
    **GitHub issue #115: matrix display style collapses to a vertical
    layout below `768px`**, mirroring the Webform Likert element's own
    technique (`web/modules/contrib/webform/css/webform.element.likert.css`)
    — hide `<thead>`, block every `<td>`. The one design decision that
    couldn't just copy Likert verbatim: Likert's per-answer label span
    is deliberately screen-reader-exposed (not `aria-hidden`), because
    Likert's radios use `aria-labelledby` pointing only at the question
    text, so the label span is what disambiguates the answer for screen
    reader users via browse-mode reading order. This element's matrix
    radios don't use `aria-labelledby` — the existing combined `#title`
    ("Item: Rank", invisible but always in the accessible-name
    computation) is already a complete, unambiguous name regardless of
    viewport. Caught on review before implementation: adding a Likert-
    style visually-hidden (not `aria-hidden`) span for the new mobile
    rank hint would have caused a screen reader to announce the radio's
    full name, then separately pick up the new span's own text as
    redundant, confusing extra content. Fixed by making the new
    `webform-ranking-matrix__rank-label` span `aria-hidden="true"`
    unconditionally — it's a pure sighted-user affordance with zero
    accessibility duty, so excluding it from the tree entirely (rather
    than toggling visually-hidden ⇄ visible by breakpoint, as Likert
    does) is both simpler and correct specifically because our
    accessible name doesn't depend on it the way Likert's does.
36. **GitHub issue #116: theme-overridable CSS custom properties for
    both display styles.** Formalizes the ad hoc Olivero-token chaining
    #115 introduced (`var(--sp1, 1.125rem)`) into a documented
    `--webform-ranking-*` vocabulary, surveyed against Olivero, Claro,
    and Gin's actual token names (Gin is a Claro subtheme —
    `base theme: claro` — and deliberately keeps Claro's unprefixed
    `--space-*`/`--input-*`/`--color-focus` names live, making them a
    genuine de-facto standard beyond just plain-Claro sites). The one
    real pitfall: a first draft declared these tokens with static
    values directly on each component's root class
    (`.webform-ranking-dragdrop { --webform-ranking-border-color: #ccc; }`).
    For an inherited property, a value *specified* on an element always
    beats one it only *inherits* — not a specificity contest — so a
    theme's own natural `:root { --webform-ranking-border-color: ...; }`
    override would silently never have reached the element at all.
    Fixed by never giving these properties a specified value anywhere
    in module CSS — only referencing `var(--webform-ranking-<name>,
    <fallback-chain>)` inline at each point of use, leaving the
    property genuinely unset so a theme's inheritance path is never
    pre-empted. Full token table and reasoning in
    [ADR-0021](adr/0021-css-custom-property-convention.md).
    `--webform-ranking-border-radius` is the one token deliberately
    *not* chained through a theme default (stays a literal `0`) — doing
    so would have silently rounded every corner on any Olivero site the
    moment this shipped, ahead of #120's actual admin-configurable
    toggle for that. The drag/drop item (a custom
    `role="listitem" tabindex="0"` div, not a native control) also
    gained an explicit `:focus-visible` ring using the same token
    chain — the move buttons/N/A checkbox are real `<button>`/`<input>`
    and already get adequate native focus styling, so they were left
    alone.
37. **GitHub issues #117 + #118: drag/drop SVG move icons + uniform
    control height, grouped into one PR** (both touch the same move-
    button/N/A-checkbox markup and CSS). **#117:** replaced the `▲`/`▼`
    text glyphs with real icons, built as nested `html_tag` render
    arrays (`buildMoveIcon()`) rather than a raw markup string — no
    prior SVG usage existed anywhere in `src/`, so this was new
    territory. Discovered Drupal core's `HtmlTag` render element
    already extends its void-element list to include `path`/`rect`/
    `circle`/etc. specifically to support building inline SVG this
    way, and confirmed via `Renderer::doRender()` that a childless
    `svg` element with nested render-array children renders correctly
    (children render into `#children` regardless of `#markup`, which
    is just an empty string when `#value` is omitted). A raw markup
    string was rejected: `Xss::filterAdmin()` (what `HtmlTag` runs
    plain-string `#value` through) doesn't allow `svg`/`path` in its
    tag list, so the icon would have been stripped. Full reasoning in
    [ADR-0022](adr/0022-inline-svg-via-nested-render-arrays.md). The
    icon itself is a simple hand-drawn two-point filled triangle (not a
    third-party icon set — avoids any licensing/attribution question
    for a shape this simple), `fill="currentColor"` so it needs no
    color token of its own. **#118:** the move buttons and N/A checkbox
    had no sizing rules at all, so they mismatched at native browser
    defaults (buttons visibly taller). Went through two revisions
    before landing on the final design. First attempt: give the
    checkbox the exact same numeric width/height as the buttons via a
    new `--webform-ranking-control-size` token. Manual review caught
    that this made the checkbox look visibly *bigger* than the buttons,
    not merely as tall — a native checkbox's visible graphic fills its
    whole box, while a button's icon sits inside a much larger padded
    box. Second attempt: leave the checkbox sized in `em` (smaller,
    proportionate to its own text) and give its label wrapper a
    `min-height` matching the buttons instead. Debugged via a temporary
    computed-style dump (`getComputedStyle()` on both controls, same
    debug-test-then-remove pattern used for #115's geometry work — see
    entry 35) after the user reported the checkbox rendering visibly
    *larger* than the buttons in their own browser, a different symptom
    than either revision was meant to produce. Root cause: browsers
    don't inherit the page's font onto native form controls by default
    (confirmed via the dump: Chrome's UA stylesheet gives `<button>`/
    `<input>` a ~13.3px "small-control" font regardless of the page's
    16px root), so the checkbox's `em`-based size was relative to that
    arbitrary, browser/OS-dependent value — not the page's type scale,
    and not reliably reproducible across browsers, which is exactly why
    the observed symptom differed from what either prior revision
    intended. **Final fix:** `font: inherit` on both controls (a well-
    known reset for this exact class of bug — Normalize.css/
    sanitize.css both carry the same rule), plus one shared, explicit,
    root-relative `--webform-ranking-control-size` (default raised from
    an earlier `1.6875rem` to `2rem`/32px — clears WCAG 2.2's 24px
    target-size minimum, SC 2.5.8, with real room to spare, short of
    the 44px "enhanced" AAA guideline, reasonable for a tightly-packed
    list-item row) applied identically to buttons and checkbox alike —
    `box-sizing: border-box` so the button's native border doesn't
    inflate its box past the declared size. A native checkbox's visible
    graphic still fills more of its box than a button's small centered
    icon does — an inherent, unavoidable native-widget rendering
    difference no amount of CSS sizing alone fully equalizes — but both
    controls now occupy the exact same box, portably. The icon's own
    `1em` width/height also benefits from the `font: inherit` fix,
    scaling against the page's real type scale instead of the same
    arbitrary UA default.

    Two more rounds of manual review, both verified via the same
    debug-test-then-remove computed-style dump: (1) a screenshot
    showing the *original* pre-fix 24px/27px split even after this fix
    landed turned out to be stale cached CSS, not a real regression —
    confirmed by re-running the dump after `drush cr`, which showed
    both controls genuinely at 32px, identical `rect.top`. (2) A real,
    separate bug the same screenshot correctly flagged: the move
    buttons' icons weren't vertically centered, off by a couple of
    pixels. Root cause: an inline SVG (an inline replaced element) sits
    on its containing span's text baseline by default, leaving
    invisible descender space below it — inflating the span's
    effective line-box height asymmetrically and throwing off the
    button's flex `align-items: center` by that same margin. Fixed with
    `display: block` on `.webform-ranking-dragdrop__icon`, the standard
    remedy for this well-known class of "mystery gap under an inline
    image/SVG" bug — confirmed via the dump that the icon's computed
    center now exactly matches the button's.

    #118's scope was deliberately expanded once more, on request: match
    the move buttons' background/text/icon color to the active theme's
    own button styling, not just their size, since the buttons
    previously had zero color styling at all (bare native chrome).
    Follows the same ADR-0021 chain-through-real-theme-tokens
    convention — Claro exposes genuine button-specific tokens
    (`--button-bg-color`/`-fg-color`/`--hover-bg-color`/`--active-bg-
    color`/`--disabled-bg-color`/`-fg-color`), which Gin (a Claro
    subtheme) doesn't override, so Claro's own values still apply
    there too; no Olivero equivalent exists, so `transparent`/
    `currentColor` are the static fallbacks — deliberately not
    inventing an arbitrary brand color. The icon needed no separate
    color token: its `fill="currentColor"` already follows the
    button's own `color`. One real catch caught during design, not
    left to be discovered later: adding an explicit `background-color`/
    `color` via a class selector unconditionally overrides the
    browser's own `:disabled` dimming (an author-stylesheet rule always
    wins over the UA stylesheet regardless of pseudo-class
    specificity) — the topmost/bottommost item's disabled move button
    would otherwise become visually indistinguishable from an enabled
    one. Fixed with an explicit `:disabled` rule, chaining through
    Claro's own disabled tokens with a static `opacity: 0.5` fallback
    affordance (matching `.webform-ranking-matrix__radio--taken`'s
    already-established convention for "inactive but not truly
    disabled") for themes with no matching token.

    Follow-up request: the buttons' focus indication should also
    adhere to the theme's own styling, not just rely on the browser's
    native default ring (the move buttons had deliberately been left
    without an explicit `:focus-visible` rule earlier in #118, on the
    reasoning that real `<button>`/`<input>` elements already get
    adequate native focus styling — true as far as "some ring appears,"
    but not "the theme's own ring appears," which is what was actually
    being asked for). Added `--webform-ranking-button-focus-color`,
    trying Claro's button-specific `--button--focus-border-color`
    first — more specific than the generic focus token
    `.webform-ranking-dragdrop__item:focus-visible` already uses —
    before falling back through that same `--webform-ranking-focus-
    color` chain, so a site that already overrode the generic focus
    color gets it here too even without a button-specific override of
    its own.

    Immediate second follow-up, correctly caught: that first pass only
    wired the focus *outline*, not background/foreground for hover,
    active, or focus — the request was for all of those to adhere to
    theme defaults, not just the ring. Checked Claro's actual token set
    to see what its generic (non-primary) button really does across
    states: it changes only `--button-bg-color` on hover/active
    (`--button--hover-bg-color`/`--button--active-bg-color`) and
    doesn't distinguish foreground at all, or background on focus —
    those per-state distinctions exist only for Claro's separate
    primary/CTA button variant (`--button-fg-color--primary`,
    `--button--focus-bg-color--primary`, etc.), which this module isn't
    using. So the honest, theme-faithful default for hover/active/focus
    foreground and focus background is "unchanged from the base state"
    — not a gap, but what Claro's own generic button actually does.
    Still wired all six as real `--webform-ranking-button-<state>-fg`/
    `-bg` tokens (falling back to the base `-fg`/`-bg` chain when unset)
    so a theme that *does* distinguish these has a real override point,
    rather than only covering what Claro itself currently needs.
38. **GitHub issue #120: scope pivoted mid-planning, from "admin-
    configurable visual options" (its literal original title) to
    theme-derived CSS tokens — no new admin form fields shipped at
    all.** Explicit user reconsideration: the goal was "blend into
    whatever theme it's dropped into," and an admin toggle doesn't
    serve that any better than the theme's own CSS custom properties
    already do — it just adds a config surface for what's really a
    styling concern. Concretely: `--webform-ranking-border-radius`
    (introduced #116/#118, deliberately left unchained/static `0` at
    the time, explicitly citing "before #120's admin toggle exists" as
    the reason) is now chained through real theme radius tokens
    (Claro/Gin's `--input-border-radius-size`/`--base-border-radius`,
    Olivero's `--border-radius`) — the original blocking rationale
    evaporated once no toggle was being added. This is a genuine
    reversal of a documented decision, not a silent rewrite: ADR-0021
    keeps the original reasoning in place under an explicit
    "**Updated in #120:**" marker explaining why it no longer applies,
    per this project's established practice for flagging deferred/
    reversed decisions instead of just erasing them. New token
    `--webform-ranking-na-opacity` (`css/webform_ranking.dragdrop.css`)
    replaces the N/A item's bare `opacity: 0.7` — `0.7` stays the
    default (unchanged behavior), Claro/Gin's
    `--input--disabled-border-opacity: 0.5` is the closest real
    semantic match found for "how much do we dim an inactive/opted-out
    state," no Olivero equivalent exists. Real visual consequence worth
    having in mind: Olivero *does* define `--border-radius`, so this is
    the first change in the #116-120 sequence that actually changes the
    rendered look on a stock, untouched Drupal site (a subtle ~3px
    corner rounding on drag/drop items/buttons that wasn't there
    before) — flagged explicitly for manual visual sign-off before
    merge, not just assumed harmless like the rest of this token
    vocabulary's static-`0`-by-default entries. `#119` should reuse
    `--webform-ranking-na-opacity` when it adds matrix's own N/A
    treatment, for consistency, rather than inventing a second one.

39. **GitHub issue #123: element-level `#states` hiding a ranking
    element on initial load permanently corrupted its own rows/items,
    even once revealed — a purely client-side bug, not the server-side
    one it first looked like.** Reported as "item rows vanish, header
    collapses to just '1st', when the element has a cross-page
    element-level visibility condition." A second report — a second
    ranking element's visibility depending on a first ranking element's
    own per-item rank value (`:input[name="ranking1[matrix][item]"]`,
    same page, no cross-page trigger at all) — showed the identical
    symptom and was folded into the same issue once traced to the same
    root cause.
    Investigation exhaustively ruled out the server side first: dozens
    of Kernel-test and real-HTTP reproduction attempts (raw render
    array, actually-rendered HTML, AJAX and non-AJAX wizard pages, Gin
    theme, the exact reported `drupal/webform:6.3.0-beta8`, a `#required`
    + `#states` core-mirroring hypothesis, a mixed same-page/cross-page
    AND condition) all produced structurally correct output — several
    of them false leads chased at length before the real mechanism
    surfaced (see the 2026-09-03 correction appended to ADR-0006's own
    entry #19 above for the closest of those). The actual bug: found by
    importing the reporter's real, full production webform YAML
    verbatim and inspecting the live DOM directly — every `<tr>` in the
    affected table, and its "2nd"/"3rd" header cells, carried the native
    HTML `hidden` attribute, genuinely present in the DOM. Traced to
    `js/webform_ranking.matrix.js`'s `initMatrix()` (and the equivalent
    in `js/webform_ranking.dragdrop.js`): both seed per-row/item
    visibility from `offsetParent === null`, a deliberate technique
    (ADR-0012, ADR-0020) for recovering a *per-item* condition's
    already-applied result — but `offsetParent` is also `null` whenever
    *any ancestor* is hidden, including when the element's own
    top-level `#states` is what's currently hiding the whole thing. Every
    row/item was wrongly seeded hidden the moment the whole element
    started hidden, permanently, since the seeding runs once and the
    only re-sync path is a per-item `state:visible` listener that never
    fires for an item with no condition of its own.
    Fixed by guarding both files' `offsetParent` consultation behind a
    `data-drupal-states`-presence check on the row/item's own element —
    only a row/item that can genuinely be individually hidden ever
    carries that attribute; one with no condition of its own is
    unconditionally visible regardless of ancestor state. Matrix needed
    nothing further (its row/column `hidden` attribute is set once,
    correctly, from the same seeding pass). Drag/drop needed one more
    piece, found via the new regression test itself: nothing had ever
    re-called `sync()` (the function computing the actual submitted
    `order`/`na` values) when only the *element's own* visibility
    changed — a real data-loss bug, not just a display one, since a
    wrongly-excluded item never made it into the real submission at
    all. Now bound on the element's own wrapper's `state:visible` event,
    deferred a tick to win the same core-`webform.states.js`
    backup/restore race ADR-0020 already solved for the N/A checkbox's
    own sync.
    New regression coverage required a `FunctionalJavascript` test —
    confirmed directly, via this same investigation, that no Kernel or
    non-JS Functional test could ever have caught this (every
    server-side check passed while the bug was live). One-time local
    setup needed: `ddev add-on get ddev/ddev-selenium-standalone-chrome`
    (see docs/TESTING.md) plus the documented `fastcgi_buffer_size` bump
    for the 502-on-large-headers gotcha, both first-time hurdles hit
    fresh during this investigation.
    Full reasoning, alternatives considered, and the exact guard/fix in
    [ADR-0023](adr/0023-element-level-hidden-initial-state-vs-per-item-visibility.md).

41. **GitHub issue #132: ranking element results/Preview HTML didn't
    bold item labels, unlike Likert's equivalent output.** Noticed
    during #129's live-environment verification, on a real form with
    both element types on the same Preview page. `WebformLikert::
    formatHtmlItem()`'s `'list'` format wraps each question label with
    `'#prefix' => '<b>', '#suffix' => ':</b> '`; `WebformRanking::
    formatHtmlItem()` just concatenated the label as plain text with no
    bold wrapper — a pure formatting inconsistency, not a data bug.
    Fixed by mirroring Likert's exact `#prefix`/`#suffix` pattern, in
    both the default and `'raw'` item-format branches (Likert bolds in
    both). New Kernel test (`WebformRankingResultsFormattingTest`)
    builds a real Webform + WebformSubmission and calls
    `formatHtml()` directly, rather than the narrower reflection-based
    coverage `WebformRankingPluginTest.php` deliberately stops short of
    — its own docblock had flagged `formatHtmlItem()`/`formatTextItem()`
    as needing exactly this heavier real-submission setup, previously
    assumed to require a Functional/Nightwatch tier test; turned out
    `WebformRankingKernelTestBase`'s existing real-Webform/-submission
    helpers (built for #129) were already sufficient at the Kernel tier.
    (Entry numbered 41, not 40 — #129's own entry above already claims
    40 on its own not-yet-merged branch at the time this was written;
    numbered ahead deliberately to avoid repeating the ADR-0022/entry-37
    collision two branches hit earlier this same day.)

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
- ~~**`#process` has zero automated coverage**~~ — resolved by Key Design
  Decision #14's FunctionalJavascript suite (matrix and dragdrop rendering
  are now both exercised end-to-end in a real browser). Formatting methods
  (`formatHtmlItem()`/`formatTextItem()`) still aren't directly covered —
  narrower gap, see below.
- ~~**Dragdrop's per-item `#states` rank echo has no automated coverage**~~
  — resolved: `WebformRankingDragdropJavaScriptTest::testStatesReactToRankSelection()`
  now exercises the full reorder→live-reveal path in a real browser.
- **Resolver's "item becomes visible because real trigger matched" path**
  is untested — only fail-closed/no-context side is covered. The new
  FunctionalJavascript matrix/dragdrop `#states` tests exercise this
  indirectly (a real submission context, a real trigger element, a real
  visibility reaction) but don't assert against
  `WebformRankingVisibilityResolver` directly — still worth a focused
  integration test if this resolver is ever touched again.
- **`formatHtmlItem()`/`formatTextItem()`** (results/CSV item formatting)
  still need a real Webform + WebformSubmission with stored data — not
  attempted in the new FunctionalJavascript suite, which focused on
  live-interaction behavior (drag, buttons, `#states`) rather than
  results-page rendering.
- **Matrix style has no dynamic rank renumbering** (dragdrop does). The
  `<thead>` is static, sized to full configured item count regardless of
  which rows are currently states.js-visible.
- ~~**`findRowContainer()` in `items_admin.js` is unverified against real
  DOM**~~ — resolved by Key Design Decision #17's redesign: the checkbox
  + row-matching heuristic this referred to no longer exists (replaced
  by a per-item dialog with no row-matching step at all).
- **No visual CSS design pass** — current CSS is still mostly structural/
  layout only, except `webform_ranking.items_admin.css` (added for
  GitHub issue #65), which deliberately mirrors core Webform's own +/-
  icon-button styling for the per-item condition builder, the matrix
  style's responsive collapse (#115), the theme-overridable custom-
  property vocabulary (#116, entry 36 above), and the drag/drop SVG
  icons/uniform control sizing (#117+#118, entry 37 above), and
  theme-derived corner rounding/N/A opacity (#120, entry 38 above —
  retitled/re-scoped mid-planning; shipped as CSS tokens, not admin
  config). What used to be a single tracking issue (#10) is now six
  focused sub-issues; remaining: #119 (matrix N/A/row-column-line/
  taken-radio styling), last.
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
- Target: Drupal ^10.3 || ^11, Webform ^6.3 (composer.json).
- Security-advisory-policy-quality module is the bar (per original ask).
- No PHP/Drupal execution available in prior environment — all fixes after
  the first several messages were driven by the user's real test/browser
  output, not independent verification. Claude Code presumably *can*
  execute — recommend actually running things rather than continuing the
  guess/correct loop where avoidable.
