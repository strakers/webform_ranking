# Fix Plan — `webform_ranking`

**Companion to:** `docs/webform-ranking-review-feedback.md`
**Module:** `web/modules/custom/webform_ranking/`
**Target stack:** Drupal 11.3.11, Webform 6.3.0-beta8

This is an ordered remediation plan. Each item states the change, the reason it
is ordered where it is, and how to prove it worked. Code sketches are
illustrative — they show the shape of the fix, not finished patches.

---

## Ground rules before starting

1. **Add a real integration test harness first (Step 0).** Most of these bugs
   exist because Webform's internals were mocked rather than exercised. Fixing
   the code without fixing the test approach means the same class of bug comes
   back.
2. **One commit per numbered step**, each with its verification evidence in the
   commit message.
3. **Do not treat a passing mocked unit test as verification** for anything
   that crosses into Webform or core Form API.

---

## Step 0 — Stand up integration testing (do this first)

### 0a. Add a kernel test base that builds real Webform entities

Nothing in the current suite builds a real `Webform` + `WebformSubmission` and
drives it through `WebformSubmissionForm`. That is the only way to catch bugs
B1 and B2.

Create `tests/src/Kernel/WebformRankingKernelTestBase.php`:

```php
protected static $modules = ['system', 'user', 'path_alias', 'webform', 'webform_ranking'];

protected function setUp(): void {
  parent::setUp();
  $this->installEntitySchema('user');
  $this->installEntitySchema('path_alias');
  $this->installEntitySchema('webform_submission');
  $this->installSchema('webform', ['webform']);
  $this->installConfig(['webform']);
}

// Helper: build a webform whose 'ranking' element carries $overrides,
// plus any $extra sibling elements.
protected function createRankingWebform(string $id, array $overrides = [], array $extra = []): Webform;

// Helper: submit and return either the saved submission or the error list.
protected function submitRanking(string $webform_id, array $data);
```

Submissions must be created as
`WebformSubmission::create(['webform_id' => $id, 'data' => [...]])` and driven
through `WebformSubmissionForm::submitWebformSubmission()`. Note: passing
element data as top-level keys to `submitFormValues()` silently drops array
values — use the `data` key.

### 0b. Add a Chrome service to DDEV

The three `FunctionalJavascript` test files cannot run today. Add a
`.ddev/docker-compose.selenium.yaml` (or `chromedriver`) service and set
`MINK_DRIVER_ARGS_WEBDRIVER` in `phpunit.xml`. Until this exists, every claim
in `CHANGELOG.md` about browser-verified fixes is unverified.

**Verification for Step 0:** the new base class runs, and one throwaway test
that submits a plain matrix ranking and asserts the stored data equals
`['item_a' => '2', 'item_b' => '1']` passes.

---

## Step 1 — B1: Fix conditional item visibility

**File:** `src/WebformRankingVisibilityResolver.php`

### The change

Replace the `validateConditions()` call with `validateState()`, and only
consider visibility-related state keys.

```php
public function resolveVisibleItemValues(array $items, ?WebformSubmissionInterface $webform_submission): array {
  $visible = [];
  foreach ($items as $item) {
    if (empty($item['states'])) {
      $visible[] = $item['value'];
      continue;
    }
    if (!$webform_submission) {
      // No submission context: fail closed (unchanged behaviour).
      continue;
    }
    if ($this->isVisible($item['states'], $webform_submission)) {
      $visible[] = $item['value'];
    }
  }
  return $visible;
}

protected function isVisible(array $states, WebformSubmissionInterface $submission): bool {
  foreach ($states as $state => $conditions) {
    // Only visibility states govern item inclusion. 'required', 'enabled',
    // etc. are meaningless for an item and are ignored.
    [$base] = explode('-', ltrim((string) $state, '!'), 2);
    if (!in_array($base, ['visible', 'invisible'], TRUE)) {
      continue;
    }
    $result = $this->conditionsValidator->validateState($state, $conditions, $submission);
    if ($result === NULL) {
      // See "NULL policy" below.
      continue;
    }
    if (!$result) {
      return FALSE;
    }
  }
  return TRUE;
}
```

`validateState()` handles the `invisible` → `!visible` alias and the negation
internally, so `TRUE` means "show this item" for both state keys. This is the
same pattern Webform uses for wizard pages
(`WebformSubmissionConditionsValidator.php:76-84`).

### Decision required: the NULL policy

`validateState()` returns `NULL` when the condition references a selector or
element that doesn't exist — i.e. **the admin's YAML has a typo**, not
"untrusted client data".

- Webform's own convention is **fail open** on NULL (`$result !== NULL && !$result`).
- The module's current docblock argues for fail-closed.

Recommend **fail open on NULL** (the `continue` above), plus a
`\Drupal::logger('webform_ranking')->warning()` naming the item and selector.
Failing closed on a typo silently deletes respondents' answers with no
diagnostic. The existing fail-closed behaviour for _no submission context_ is
correct and should stay — that is a genuinely different branch.

Whichever way this goes, update the class docblock, which currently describes
behaviour that will no longer be accurate.

### Verification

Add `tests/src/Kernel/WebformRankingConditionalItemTest.php` using the Step 0
base. Assert all four quadrants against a **real** conditions validator:

| Trigger value | Item state                           | Expected                              |
| ------------- | ------------------------------------ | ------------------------------------- |
| `yes`         | `visible: {trigger: {value: yes}}`   | item included, rank saved             |
| `no`          | `visible: {trigger: {value: yes}}`   | item excluded, rank dropped, no error |
| `yes`         | `invisible: {trigger: {value: yes}}` | item excluded                         |
| `no`          | `invisible: {trigger: {value: yes}}` | item included                         |

Plus the regression case that currently fails:

- Condition satisfied, conditional item ranked **1st**, normal item ranked
  **2nd** → **submits cleanly**. Today this produces
  _"ranks must be assigned starting from the top, with no gaps."_

### Also in this step

Rewrite `tests/src/Unit/WebformRankingVisibilityResolverTest.php`. The mocked
expectations at line 51 assert the _wrong_ call shape and must change to
`validateState`. Keep the unit tests for the no-context and no-states
branches; the true/false evaluation belongs in the kernel test above, against
the real service.

---

## Step 2 — B2: Stop a scalar `#default_value` from fataling the form

**Files:** `src/Plugin/WebformElement/WebformRanking.php`,
`src/Element/WebformRanking.php`

Two changes, both wanted:

### 2a. Hide the property (root cause)

The "Default value" textfield cannot express a ranking, so it should not be
offered. In `defineDefaultProperties()`:

```php
$properties = [...] + parent::defineDefaultProperties();
unset($properties['default_value']);
return $properties;
```

Verify `hasProperty('default_value')` then returns `FALSE` and the field
disappears from the element config form. (If Webform's base `form()` still
renders it, set `$form['default']['#access'] = FALSE` in `form()` instead.)

### 2b. Harden the boundary (defence in depth)

Existing configs may already carry a bad value, and `#default_value` can also
be set programmatically or by another module's alter hook. In `prepare()`,
replace the current one-sided guard:

```php
$element['#default_value'] = is_array($element['#default_value'] ?? NULL)
  ? WebformRankingConverter::matrixToCanonical($element['#default_value'])
  : ['values' => [], 'na' => []];
```

This also removes the `!empty()` check, which currently leaves `[]` untouched
and produces a bare `[]` as `#value` — inconsistent with the canonical shape
every other consumer expects.

### Verification

Kernel test: a webform whose ranking element has `'#default_value' => 'oops'`
builds and renders without throwing. Today this is:

```
TypeError: WebformRankingConverter::canonicalToMatrix(): Argument #1
($canonical) must be of type array, string given
```

---

## Step 3 — B3: Make `#required` work

**File:** `src/Element/WebformRanking.php`, in `validateWebformRanking()`

Core's required check can't see an empty ranking because
`['values' => [], 'na' => []]` has a count of 2. Handle it explicitly, after
the visibility filtering and before the `#required_all` block:

```php
if (!empty($element['#required']) && !$values && !$na) {
  $form_state->setError($element, $element['#required_error']
    ?? $translation->translate('@name field is required.', ['@name' => $title]));
  return;
}
```

Note the ordering matters: it must run _after_ stale/invisible entries are
dropped, so a submission consisting only of hidden items still counts as
empty.

### Verification

Kernel test matrix:

| `#required` | `#required_all` | value           | expected                             |
| ----------- | --------------- | --------------- | ------------------------------------ |
| TRUE        | FALSE           | empty           | **error** (currently saves silently) |
| TRUE        | FALSE           | one item ranked | passes                               |
| FALSE       | FALSE           | empty           | passes                               |
| FALSE       | TRUE            | partial         | error                                |

---

## Step 4 — H3: Delete the config schema file

Delete `config/schema/webform_ranking.schema.yml` entirely.

Rationale (see feedback doc, H3): Webform stores element config as a raw YAML
text blob, so nothing reads it; its declared parent type
`webform.webform.webform_element` does not exist; and its own type name
collides with the config object name of a webform entity whose machine name is
`webform_ranking`.

**Verification:** `drush config:inspect` (or a `KernelTestBase` with
`$strictConfigSchema = TRUE`) reports no schema errors after removal, and
creating a webform with machine name `webform_ranking` behaves normally.

---

## Step 5 — H1: Validate item values at config time

**File:** `src/Plugin/WebformElement/WebformRanking.php`,
`validateConfigurationForm()`

Add per-value validation alongside the existing uniqueness check:

```php
if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $value)) {
  $form_state->setErrorByName('items', $this->t(
    'Item values may only contain letters, numbers, underscores, hyphens and periods. "@value" is not valid.',
    ['@value' => $value]));
}
if (mb_strlen($value) > 128) {
  $form_state->setErrorByName('items', $this->t(
    'Item values must be 128 characters or fewer. "@value" is too long.',
    ['@value' => $value]));
}
```

The 128 limit is not arbitrary: item values become the
`webform_submission_data.property` column, which is `varchar(128)` **and part
of the primary key** (`WebformSubmissionStorageSchema.php:47`). Webform's own
Likert element applies the same limit via `#options_value_maxlength => 128`.

Also worth adding here: the field description already promises _"Item values
… cannot be changed once submissions exist"_, but nothing enforces it. Either
enforce it (compare against saved values when
`$webform->getNumberOfSubmissions()` is non-zero) or soften the description.

### Verification

- Kernel/Functional test: saving an item value of `a"b` produces a
  configuration form error.
- Regression: the currently-produced selector
  `:input[name="ranking[matrix][a"b]"]` can no longer be generated.

---

## Step 6 — H2: Fix the `#states` selector options

**File:** `src/Plugin/WebformElement/WebformRanking.php`

Replace the `getElementSelectorOptions()` override with
`getElementSelectorInputsOptions()`, the extension point the parent is built
around:

```php
protected function getElementSelectorInputsOptions(array $element) {
  $style = $element['#ranking_style'] ?? 'matrix';
  $inputs = [];
  foreach ($element['#items'] ?? [] as $item) {
    $name = $style === 'dragdrop'
      ? "dragdrop][rank][{$item['value']}"
      : "matrix][{$item['value']}";
    $inputs[$name] = $this->t('@item [Rank]', ['@item' => $item['label']]);
  }
  return $inputs;
}
```

The parent wraps each key as `:input[name="{$webform_key}[{$input_name}]"]`,
so the embedded `][` produces the correct nested name. This gets three things
at once: the broken whole-element selector `:input[name="ranking"]` disappears,
the options become properly grouped under the element title, and the override
is 12 lines instead of 25.

`getElementSelectorInputValue()` stays as-is — it already parses both selector
shapes correctly.

### Bonus, cheap and worth doing

Implement `getElementSelectorSourceValues()` so the condition builder offers a
dropdown of valid rank values instead of a free-text box:

```php
public function getElementSelectorSourceValues(array $element) {
  $ranks = range(1, count($element['#items'] ?? []));
  $values = array_combine($ranks, $ranks);
  if (!empty($element['#allow_na'])) {
    $values['na'] = (string) ($element['#na_label'] ?? $this->t('N/A'));
  }
  $source = [];
  foreach (array_keys(reset($this->getElementSelectorOptions($element)) ?: []) as $selector) {
    $source[$selector] = $values;
  }
  return $source;
}
```

(See `WebformLikert::getElementSelectorSourceValues()` for the pattern.) This
directly reduces the "raw YAML" pain the README lists as a limitation.

### Verification

Assert `array_keys($plugin->getElementSelectorOptions($element))` contains no
bare `:input[name="ranking"]`, and that the returned array is grouped
(`[$title => [...selectors]]`).

---

## Step 7 — Front-end fixes

Group these into one commit; each is small.

### 7a. Library dependencies

`webform_ranking.libraries.yml` — add to `element.dragdrop`:

```yaml
dependencies:
  - core/drupal
  - core/once
  - core/drupal.states
```

`core/drupal.states` is what's actually being integrated with, and it pulls in
jQuery. Then simplify `dragdrop.js:365-374` to the jQuery path only and delete
the dead native-listener fallback and its "verification note" comment.

### 7b. Touch support

`css/webform_ranking.dragdrop.css` — add to `.webform-ranking-dragdrop__item`:

```css
touch-action: none;
```

Without this, a touch drag is claimed by the browser for scrolling and fires
`pointercancel`. The README currently advertises touch gestures.

### 7c. Accessibility

- `src/Element/WebformRanking.php:297` — move the hidden `order`/`na`/`rank`
  inputs and the live-region `<div>` **outside** the `role="list"` container.
  A `list` role's owned children must all be `listitem`.
- `js/webform_ranking.dragdrop.js:212` — drop the unconditional
  `item.focus()` in `moveItem()`. Return focus to whatever triggered the move
  (the button if a button was clicked, the item if arrow keys were used), so
  repeated keyboard activation of "Move up" keeps working.
- `src/Element/WebformRanking.php:419-438` — the `▲`/`▼` glyphs should be
  wrapped in an `aria-hidden="true"` span so they aren't announced alongside
  the `aria-label`.

### 7d. CSS scope

`css/webform_ranking.matrix.css:22` — delete the unscoped `.visually-hidden`
block. Core's `system/base` already provides a more complete version (with
`clip-path` and `word-wrap`), and the drag-drop live region relies on it
anyway since `element.dragdrop` never loads this file.

### Verification

Requires Step 0b. Extend the existing `FunctionalJavascript` tests with: a
touch-emulated drag; a keyboard "Move up" pressed twice in a row from the
button; and an axe/`assertSession` check that the list container has only
`listitem` children.

---

## Step 8 — Metadata and packaging

### 8a. Version constraints

- `webform_ranking.info.yml` and `composer.json`: change `^10.1 || ^11` to
  `^10.3 || ^11`. `Drupal\Core\Render\Element\FormElementBase` was added in
  10.3; the module cannot install below that.
- `composer.json`: narrow `drupal/webform` from `^6.2` to the range actually
  tested. Given the depth of internals this module touches, `^6.3` is the
  honest constraint unless 6.2 is explicitly tested.

### 8b. Annotation → attribute

`src/Element/WebformRanking.php:36` — replace `@FormElement("webform_ranking")`
with `#[FormElement('webform_ranking')]` and
`use Drupal\Core\Render\Attribute\FormElement;`. Still functional on 11.3, but
removed in Drupal 12.

### 8c. Packaging decision

The module ships as a standalone drupal.org project vendored into
`web/modules/custom/` — its own `composer.json`, `LICENSE.txt`,
`.gitattributes`, `.editorconfig`, and a `.gitignore` that ignores `/web/`,
`/vendor/`, `/sites/` and `.ddev/`.

Pick one:

- **Preferred:** require it via Composer from its git repository, and remove
  it from `web/modules/custom/`. Upstream fixes then arrive through
  `ddev composer install` rather than hand-merges.
- **Or:** keep it vendored and delete the standalone-project files
  (`composer.json`, `.gitignore`, `.gitattributes`, `LICENSE.txt` stays if the
  licence needs to travel).

---

## Step 9 — Behaviour polish

### 9a. Randomization stability

`src/Plugin/WebformElement/WebformRanking.php:285` — `shuffle()` runs on every
form build, so rows re-randomize after a validation error or wizard-step
navigation.

Shuffle once per form session and persist the order, e.g. seed from
`$webform_submission->uuid()` (or a value stashed in `$form_state`) so the
order is stable within a session but varies between respondents:

```php
if (!empty($element['#randomize_item_order'])) {
  mt_srand(crc32($webform_submission ? $webform_submission->uuid() : ''));
  shuffle($element['#items']);
  mt_srand();
}
```

Verify by submitting an invalid ranking and confirming the row order is
unchanged on the rebuilt form.

### 9b. Dead code and standards

- `src/Plugin/WebformElement/WebformRanking.php:260` — remove the no-op
  `$form_state->setValue('items', $items)`.
- `src/Element/WebformRanking.php:175` — remove the unused `$delta`.
- `src/Element/WebformRanking.php:487` — replace the
  `formatPlural(1, '@position', '@position', ...)` wrapper with plain `t()`.
- `tests/src/Kernel/WebformRankingValidationKernelTest.php:59` — remove the
  useless `setUp()` override (the comment inside it can move to the class
  docblock).
- Run `phpcbf --standard=Drupal,DrupalPractice` over `src/` and `tests/`, then
  re-run `phpcs`. Ignore the JS `TRUE/FALSE/NULL` casing errors — those are a
  known Coder false positive on JavaScript files.
- Update the `CHANGELOG.md` "full compliance with Drupal's coding standards"
  claim once the above is actually true.

### 9c. Comment density

See the feedback doc's maintainability note. Suggested pass, best done last so
it doesn't conflict with the functional commits:

- Move development history (what was tried, which bug was hit, what a previous
  version did) out of docblocks and into `docs/CONTINUATION.md`, which already
  exists for exactly this.
- Keep short notes only where behaviour is genuinely non-obvious today. The
  pointer-capture workaround at `js/webform_ranking.dragdrop.js:323` is the
  model — it explains a real, invisible browser behaviour in four lines.
- Delete comments describing removed code, e.g.
  `src/Element/WebformRanking.php:611-624`.
- Correct the `WebformRankingVisibilityResolver` class docblock, which
  currently asserts the correctness of the call this plan fixes in Step 1.

---

## Suggested sequencing

| Order | Steps         | Rationale                                         |
| ----- | ------------- | ------------------------------------------------- |
| 1     | Step 0        | Nothing below can be honestly verified without it |
| 2     | Steps 1, 2, 3 | The blockers; ship as a group                     |
| 3     | Steps 4, 5, 6 | Correctness and admin-UX; low risk, independent   |
| 4     | Step 7        | Front-end; gated on 0b for verification           |
| 5     | Steps 8, 9    | Metadata, packaging, polish                       |

Steps 4, 5, 6 and 8 are independent of each other and can be parallelised
across reviewers if useful.

---

## Definition of done

- [ ] A conditional item whose condition is satisfied can be ranked and saved,
      in both display styles, proven by a kernel test against the real
      conditions validator.
- [ ] No `#default_value` an admin can enter through the UI produces a fatal.
- [ ] "Required" produces a validation error on an empty ranking.
- [ ] Item values are constrained to storage-safe characters and ≤128 chars.
- [ ] The condition builder offers only selectors that match real DOM inputs.
- [ ] `phpcs --standard=Drupal,DrupalPractice` is clean (modulo the JS
      false positives).
- [ ] The `FunctionalJavascript` suite actually runs in CI, and passes.
- [ ] Version constraints match what the code requires and what has been
      tested.
