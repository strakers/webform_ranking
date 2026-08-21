# Code Review Feedback — `webform_ranking`

**Module:** `web/modules/custom/webform_ranking/`
**Reviewed:** 2026-08-07
**Reviewed against:** Drupal 11.3.11, Webform 6.3.0-beta8 (this site's installed stack)
**Branch:** `NMS-1044-ranking-question-component`

---

## How this review was done

Findings marked **Confirmed** were reproduced by executing code against the real
stack in DDEV — throwaway kernel probes that built actual `Webform` and
`WebformSubmission` entities, drove submissions through
`WebformSubmissionForm`, and inspected the resulting form values, validation
errors, and stored data. Those probes have been deleted; the module directory
is unchanged by this review.

Also run:

- `phpunit` on the shipped Unit suite — **34 tests, 47 assertions, all pass**.
- `phpunit` on the shipped Kernel suite — **33 tests, 68 assertions, all pass**
  (deprecation notices come from Webform itself, not this module).
- `phpcs --standard=Drupal,DrupalPractice` across the module.
- `php -l` on every PHP file — clean.

The three `FunctionalJavascript` test files could **not** be run: this DDEV
project has no Chrome/selenium service configured. Roughly 800 lines of the
test suite are therefore unverified here.

---

## What works well

Worth saying plainly, because a lot of this is done right:

- **The value model is sound.** Keeping one canonical in-memory shape
  (`['values' => [...], 'na' => [...]]`) and converting to a flat
  `item => rank` map only at the Webform storage seam is the correct
  architecture. `WebformRankingConverter` is pure, well-tested, and does its
  job.
- **The matrix and drag-drop submission round-trips work.** Verified
  end-to-end: submit → validate → save → reload → re-validate all produce
  correct data for both display styles.
- **`getElementSelectorInputValue()` genuinely fixes** the "Array to string
  conversion" problem its docblock describes.
- **The one-`radio`-per-cell matrix construction is correct** and mirrors
  core's `Radios::processRadios()` faithfully.
- **The documentation is unusually candid.** `docs/CONTINUATION.md` explicitly
  flags several areas as unverified — and those flags turned out to be
  accurate. That honesty is what made this review efficient.

---

## Blockers

These should be fixed before the module goes near a real form.

### B1. Conditional items are always dropped server-side — the feature does not work

**Confirmed.** `src/WebformRankingVisibilityResolver.php:92`

```php
$this->conditionsValidator->validateConditions($item['states'], $webform_submission)
```

`WebformSubmissionConditionsValidator::validateConditions()` expects the
_inner conditions_ array (`[selector => condition]`), not a state-keyed
`#states` array. Given
`['visible' => [':input[name="trigger"]' => ['value' => 'yes']]]`, it treats
the literal string `'visible'` as a selector,
`getSelectorInputName('visible')` fails to match, and the method returns
**NULL**. The resolver's truthiness check treats NULL as "not visible", so
**every item carrying a condition is excluded regardless of whether the
condition is satisfied.**

Measured directly against a real submission where the condition _was_
satisfied:

```
validateConditions(full #states array): NULL
validateConditions(inner conditions):   true
resolveVisibleItemValues(...):          ["item_a"]   ← item_b wrongly excluded
```

Downstream effect, reproduced with a browser-shaped matrix POST
(`item_b` = 1st, `item_a` = 2nd, condition satisfied):

```
errors = ["Ranking: ranks must be assigned starting from the top, with no gaps
           — a lower rank cannot be used unless every rank above it is also used."]
```

The respondent sees a nonsensical error they cannot resolve — the "gap" is
created by validation itself silently removing a visible item's rank from the
raw input before the sequential-rank check runs. If instead they rank the
conditional item _lower_ than a normal item, there is no error and their
answer is **silently discarded** from the saved submission.

Secondary defect in the same method: the state key is ignored entirely, so an
`invisible:` condition would be evaluated identically to a `visible:` one.
`validateState($state, $conditions, $submission)` is the API that handles
both.

### B2. A scalar `#default_value` is a fatal white screen

**Confirmed.** `src/Element/WebformRanking.php:153`,
`src/Plugin/WebformElement/WebformRanking.php:326`

`hasProperty('default_value')` returns TRUE for this element, so Webform's
element configuration form presents a **"Default value"** textfield. Anything
an admin types there is stored as a string. `prepare()`'s `is_array()` guard
correctly skips it — and then hands it straight to a typed parameter:

```
TypeError: Drupal\webform_ranking\WebformRankingConverter::canonicalToMatrix():
Argument #1 ($canonical) must be of type array, string given,
called in .../src/Element/WebformRanking.php on line 153
```

The form white-screens for every respondent. This is one stray keystroke on
the admin form away from taking a live form down.

### B3. Marking the element "Required" does nothing

**Confirmed.** `src/Element/WebformRanking.php:104`

`valueCallback()` returns `['values' => [], 'na' => []]` for an untouched
element. Core's required check (`FormValidator.php:265`) tests
`count($value) == 0` — the count here is 2, so `#required_but_empty` is never
set and no error is raised.

Reproduced: a form with `#required: true, #required_all: false` saved a
completely empty ranking with zero validation errors. Admins who tick
"Required" get no enforcement at all unless they _also_ happen to leave
`required_all` on.

---

## High

### H1. Item values are unvalidated and can produce broken selectors

**Confirmed.** `src/Plugin/WebformElement/WebformRanking.php:244`

`validateConfigurationForm()` checks only uniqueness and a minimum count.
Item values are used verbatim as HTML input-name segments, jQuery `#states`
selectors, `data-` attribute values, and the
`webform_submission_data.property` column. An item value of `a"b` produces:

```
selector: :input[name="ranking[matrix][a"b]"]     ← malformed, matches nothing
rendered: name="ranking[matrix][a&quot;b]"
```

The condition never binds, silently. Nothing at config time prevents this.

### H2. The condition builder still offers a selector that matches nothing

**Confirmed.** `src/Plugin/WebformElement/WebformRanking.php:378`

`getElementSelectorOptions()` appends per-item selectors to
`parent::getElementSelectorOptions()`'s output. The parent returns the
whole-element selector `:input[name="ranking"]`, which corresponds to no real
DOM input for this element. Admins building a condition see it listed
alongside the working per-item entries and can pick it.

Confirmed output:

```
[":input[name=\"ranking\"]",
 ":input[name=\"ranking[matrix][a\"b]\"]",
 ":input[name=\"ranking[matrix][item_b]\"]"]
```

The intended extension point is `getElementSelectorInputsOptions()`. Using it
makes the parent build a correctly _grouped_ option list **and** removes the
bogus top-level entry — the current override does neither.

### H3. `config/schema/webform_ranking.schema.yml` is dead code, and shadows a real config name

Webform stores element configuration as a raw YAML **text blob**
(`webform.entity.webform.schema.yml:46` — `elements: type: text`). There is no
per-element config schema mechanism in Webform; nothing reads this file.

Beyond being dead, it is actively hazardous: `webform.webform.webform_ranking`
is the config _object name_ pattern for a webform entity whose machine name is
`webform_ranking`. If such a webform is ever created, this definition shadows
its real schema. Its declared parent type
`webform.webform.webform_element` does not exist anywhere in Webform or core
(verified by grep across both).

### H4. The unit tests encode B1 as expected behaviour

`tests/src/Unit/WebformRankingVisibilityResolverTest.php:51`

```php
$conditionsValidator->expects($this->once())
  ->method('validateConditions')
  ->with($states, $submission)      // ← asserts the wrong call shape
  ->willReturn(TRUE);               // ← mocks away the NULL that real Webform returns
```

Every conditional-visibility test mocks the collaborator, so the contract
mismatch is invisible. `docs/CONTINUATION.md:700` already flags the
"item becomes visible because a real trigger matched" path as untested — that
is precisely where the bug lives.

The fix isn't just this one test. The pattern to correct is _mocking a
contract that was never verified against the real collaborator_. Every place
this module reaches into Webform internals deserves at least one integration
test that touches the real service.

---

## Medium

### M1. `element.dragdrop` doesn't declare its jQuery dependency

`webform_ranking.libraries.yml:16`, `js/webform_ranking.dragdrop.js:365`

The library declares only `core/drupal` and `core/once`. The behaviour then
reads `window.jQuery` to bind `state:visible` (states.js fires it as a jQuery
event, not a native `CustomEvent`). On Drupal 10/11, `core/drupal` no longer
pulls in jQuery — so whether jQuery is present is incidental, and when it
isn't, the native-listener fallback binds a listener that will never fire.
Position renumbering on conditional show/hide silently stops working.

Add `core/jquery` (or better, `core/drupal.states`, which is what's actually
being integrated with).

### M2. Touch drag is broken

`css/webform_ranking.dragdrop.css:10`

Pointer-Events dragging requires `touch-action: none` on the draggable
element. Without it the browser claims the gesture for scrolling and fires
`pointercancel`. The README advertises touch gestures as a supported
interaction.

### M3. Accessibility gaps in the drag-drop list

`src/Element/WebformRanking.php:297`, `js/webform_ranking.dragdrop.js:212`

- The `role="list"` container has the hidden `order`/`na`/`rank` inputs and
  the live-region `<div>` as direct children, alongside the
  `role="listitem"` items. A `list` role requires its owned children to be
  `listitem`; the extra children make the list semantics invalid.
- `moveItem()` calls `item.focus()`, which steals focus off the move button
  the user just activated. A keyboard user pressing Enter on "Move up"
  repeatedly gets exactly one move — subsequent presses land on the item
  container, which has no Enter handler.
- The `▲`/`▼` glyphs are exposed to assistive tech alongside the
  `aria-label`; they should be `aria-hidden`.

### M4. `matrix.css` redefines a core utility class globally

`css/webform_ranking.matrix.css:22`

`.visually-hidden` is redefined unscoped, in an older form missing
`clip-path` and `word-wrap`. Meanwhile the drag-drop live region uses that
same class but `element.dragdrop` never loads this file — so it depends on
core's `system/base` definition anyway. Either scope the rule to the module's
own namespace or delete it and rely on core.

### M5. Version metadata is inaccurate

- `webform_ranking.info.yml:5` and `composer.json` declare
  `^10.1 || ^11`, but `src/Element/WebformRanking.php:38` extends
  `Drupal\Core\Render\Element\FormElementBase`, which **was added in Drupal
  10.3**. The module cannot install on 10.1 or 10.2.
- `composer.json` requires `drupal/webform: ^6.2`; this site runs
  `6.3.0-beta8`. Given how much of this module reaches into Webform
  internals (`WebformSubmissionConditionsValidator`,
  `getElementSelectorInputValue()`, `WebformMultiple`, `WebformCodeMirror`),
  the supported range should be narrowed to what is actually tested.
- `@FormElement` annotation is deprecated in favour of the
  `#[FormElement]` attribute. It still works on 11.3 but is removed in 12.

### M6. Randomization reshuffles on every form rebuild

`src/Plugin/WebformElement/WebformRanking.php:285`

`shuffle()` runs inside `prepare()`, which executes on every build — including
validation-error rebuilds, AJAX rebuilds, and wizard steps. After a failed
submit the rows jump to a new random order while the user's selections stay
attached to their items. Disorienting, and it undermines the bias-reduction
rationale for the feature.

### M7. FunctionalJavascript coverage is unverifiable in this environment

Three test files (~790 lines) require a real browser. There is no
Chrome/selenium service in `.ddev/`. Before relying on the claims in
`CHANGELOG.md` about browser-verified fixes, that service needs to exist and
the suite needs to be run.

---

## Low / cleanup

- `src/Plugin/WebformElement/WebformRanking.php:260` —
  `$form_state->setValue('items', $items)` is a no-op; `$items` is unmodified.
- `src/Element/WebformRanking.php:175` — unused `$delta` in the `foreach`.
- `CHANGELOG.md` claims "full compliance with Drupal's coding standards".
  `phpcs --standard=Drupal,DrupalPractice` still reports the unused variable,
  a "useless method overriding" warning
  (`WebformRankingValidationKernelTest.php:59`), and several inline-comment
  spacing warnings. (The JS `TRUE/FALSE/NULL` errors are Coder false
  positives — the sniff wrongly applies PHP constant casing to JavaScript —
  and can be ignored.)
- `src/Element/WebformRanking.php:487` — `formatPlural(1, '@position',
'@position', ...)` is a plural wrapper around a string with no plural form.
  Plain `t()` is clearer.
- Two similarly-named kernel test files (`WebformRankingValidationTest` and
  `WebformRankingValidationKernelTest`) with overlapping scope. The
  distinction is documented but easy to trip over; consider renaming to
  reflect what each actually covers.

---

## Packaging concern

The module is vendored into `web/modules/custom/` as a **standalone
drupal.org project**: it ships its own `composer.json`, `LICENSE.txt`,
`.gitattributes`, `.editorconfig`, and a `.gitignore` that ignores `/web/`,
`/vendor/`, `/sites/`, and `.ddev/` — paths that make sense in the module's
own repository and are meaningless (and confusing) here.

For NMS, pulling it in via Composer from its git repository would be cleaner:
upstream fixes flow in through `composer install` rather than hand-merges, and
the site repo stops carrying a second project's metadata. If it stays
vendored, the standalone-project files should be stripped.

---

## Maintainability note: comment density

The module is roughly **2,100 lines of source carrying more comment than
code**. Several docblocks are 30+ lines of narrative development history —
which bug was hit, what was tried, what a previous version did, what a GitHub
issue said. Examples: `src/Plugin/WebformElement/WebformRanking.php:113-176`
(a 60-line comment on a single form element),
`src/Element/WebformRanking.php:611-624` (a 14-line comment explaining code
that was _removed_).

This is well-intentioned, but it ages badly: the narrative describes states
the code is no longer in, and it buries the handful of comments that genuinely
matter (the pointer-capture workaround at `dragdrop.js:323` is a good one, and
it's hard to spot among the rest). Development history belongs in commit
messages and `docs/CONTINUATION.md`; the code should carry short notes about
what is non-obvious _right now_.

Worth noting: some of the longest docblocks assert behaviour that turned out
to be wrong. `WebformRankingVisibilityResolver`'s class docblock spends a
paragraph explaining why building on Webform's own conditions engine is the
right call — and the actual call into that engine is broken. Confident prose
around unverified code is worse than no prose.

---

## Recommendation

Block on **B1, B2, B3**. Treat **H3** and **H4** as part of fixing B1 — the
bug exists because the contract was mocked rather than exercised, and it will
come back the same way if only the one line is patched.

See `docs/webform-ranking-fix-plan.md` for the ordered remediation plan.
