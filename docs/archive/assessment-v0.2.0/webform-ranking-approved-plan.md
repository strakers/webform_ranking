# Webform Ranking — Fix the Blockers (B1, B2, B3 + H3, H4)

## Context

A colleague ran `webform_ranking` through Claude Code's agentic review against a
different site (an "NMS" project, branch `NMS-1044-ranking-question-component`,
where this module was vendored into `web/modules/custom/`), producing
`docs/assessment/webform-ranking-review-feedback.md` and
`docs/assessment/webform-ranking-fix-plan.md`. This repo (`candid-assessment`
branch) is the module's own canonical drupal.org project home, not that site —
but I re-verified essentially every claim directly against this repo's code,
the vendored Webform module (`web/modules/contrib/webform`), and Drupal core
(`web/core`), and the substance holds up almost line-for-line:

- **B1** (conditional items always dropped): confirmed exactly.
  `WebformRankingVisibilityResolver.php:92` calls
  `validateConditions($item['states'], $submission)`, but Webform's own
  interface docblock (`WebformSubmissionConditionsValidatorInterface.php:76-91`)
  confirms `validateConditions()` expects the _inner_ conditions array
  (`[selector => condition]`), not a state-keyed array like
  `['visible' => [...]]`. `validateState($state, array $conditions, $submission)`
  (`WebformSubmissionConditionsValidator.php:594-608`) is the real entry point —
  it unwraps the state key (handles `visible`/`invisible`/`!visible` negation)
  and calls `validateConditions()` correctly underneath.
- **B2** (scalar `#default_value` white-screens the form): confirmed. The
  `is_array()` guard in `prepare()` (`Plugin/WebformElement/WebformRanking.php:323`)
  skips scalar values and leaves them untouched, which then reach
  `WebformRankingConverter::canonicalToMatrix()` — a typed `array $canonical`
  parameter — via `Element/WebformRanking.php`'s `buildMatrix()`/`buildDragDrop()`.
- **B3** (`#required` does nothing): confirmed. `valueCallback()` returns
  `['values' => [], 'na' => []]` for an untouched element — count 2, so core's
  `count($value) == 0` required-check never fires — and
  `validateWebformRanking()` has no explicit required check anywhere.
- **H3** (dead, hazardous config schema): confirmed twice over.
  `webform.entity.webform.schema.yml:46` stores `elements: type: text` (a raw
  blob — nothing reads per-element schema). Separately,
  `webform.entity.webform.schema.yml:1` declares a `'webform.webform.*':`
  wildcard pattern — meaning our schema's key `webform.webform.webform_ranking`
  really would collide with a webform entity machine-named `webform_ranking`.
  Its declared parent type `webform.webform.webform_element` does not exist
  anywhere in core or contrib (grep confirmed empty).
- **H4** (unit test encodes the bug): confirmed.
  `tests/src/Unit/WebformRankingVisibilityResolverTest.php:40-43` mocks
  `validateConditions()` with the `$states`-shaped array and `willReturn(TRUE)`
  — asserting the wrong call shape entirely, which is exactly why B1 shipped
  undetected. Also confirmed: neither existing kernel test
  (`WebformRankingValidationKernelTest`, `WebformRankingValidationTest`)
  builds a real `Webform`/`WebformSubmission` — both say so explicitly in
  their own docblocks — so there is genuinely no test today that exercises
  the true/false path against a live conditions validator.

Decisions confirmed with the user for this pass:

- **NULL policy for B1**: fail **open** on `validateState()` returning NULL
  (unresolvable selector/typo), logging a warning — matches Webform's own
  convention for wizard pages. The existing fail-**closed** behavior for "no
  submission context at all" is a different, correct branch and stays as-is.
- **Scope**: blockers only (Step 0 harness + B1/B2/B3 + H3 + H4, per the
  review's own recommendation that H3/H4 belong with the B1 fix). High/Medium/
  Low items (H1, H2, M1-M7, packaging, metadata, comment density) are
  deliberately out of scope for this pass.

Note: the review's "Packaging concern" section and the CHANGELOG "full
compliance" Low-item don't apply to this repo/branch as written (this repo IS
the module's home; that CHANGELOG wording exists only on the unmerged
`feature/coding-standards-drupalorg-submission-prep` branch) — no action
needed here, just flagging so it isn't mistaken for a missed finding.

## GitHub issue tracking

Repo is `strakers/webform_ranking` (`gh` authenticated, confirmed). Existing
labels: `bug`, `documentation`, `duplicate`, `enhancement`, `good first issue`,
`help wanted`, `invalid`, `question`, `wontfix` — no custom severity labels,
so issue bodies will state the review's severity (B/H/M/Low) in text rather
than inventing new labels. Three relevant issues already exist and will be
cross-referenced, not duplicated: **#13** (visual conditions builder — relates
to H2's bonus suggestion), **#9** (automated a11y testing — relates to M3/M7),
**#10** (visual design pass — unrelated).

Every issue body links back to the specific section of
`docs/assessment/webform-ranking-review-feedback.md` (and `-fix-plan.md` where
relevant) so full technical detail isn't duplicated in the issue itself.

**In-scope issues** (created now, closed by this PR — labelled `bug`):

1. Add a kernel test harness for real Webform/WebformSubmission integration
   tests (Step 0) — labelled `enhancement` (test infra, not a defect).
2. Conditional ranking items are always dropped server-side (B1).
3. A scalar `#default_value` fatally crashes the ranking element (B2).
4. Marking the ranking element "Required" has no effect (B3).
5. Delete the dead/hazardous config schema and fix the unit test that encoded
   the B1 bug (H3 + H4, bundled — H4 is literally the test-side half of the
   B1 fix).

**Backlog issues** (created now, left open, out of scope for this PR): 6. Item values are unvalidated and can produce broken `#states` selectors (H1). 7. Ranking's `#states` selector options include one that matches no real DOM
input (H2) — note "relates to #13" in the body. 8. `element.dragdrop` library doesn't declare its jQuery/`drupal.states`
dependency (M1). 9. Touch dragging is broken on the drag/drop ranking list (M2). 10. Accessibility gaps in the drag/drop ranking list — invalid list semantics,
focus stolen from move buttons, un-hidden glyphs (M3) — note "relates to
#9". 11. `matrix.css` redefines `.visually-hidden` globally, shadowing/diverging
from core's version (M4). 12. Module version metadata is inaccurate — `core_version_requirement`
understates the real minimum (10.3), `drupal/webform` constraint is wider
than what's tested, `@FormElement` annotation is deprecated (M5). 13. Randomized item order reshuffles on every form rebuild, including
validation-error rebuilds (M6) — `enhancement`. 14. Add a Chrome/Selenium service to DDEV so the `FunctionalJavascript` suite
can actually run (M7) — note "relates to #9" — `enhancement`. 15. Remove the no-op `$form_state->setValue('items', $items)` call —
`enhancement`. 16. Remove the unused `$delta` in `WebformRanking::buildMatrix()`'s foreach —
`enhancement`. 17. Replace the `formatPlural()` wrapper around a non-plural string with plain
`t()` in `getRankLabels()` — `enhancement`. 18. Rename/clarify the two overlapping kernel test files
(`WebformRankingValidationTest` vs `WebformRankingValidationKernelTest`)
— `enhancement`.

**Deliberately not filed as issues** (noted here instead, so nothing is
silently lost): the review's "Packaging concern" section describes a _different_
repo context (this module vendored into a consuming site) that doesn't apply
to this repo, which is the module's own home; and the Low-item about
`CHANGELOG.md` claiming "full compliance with Drupal's coding standards"
doesn't match this branch's actual `CHANGELOG.md` content (that wording exists
only on the unmerged `feature/coding-standards-drupalorg-submission-prep`
branch) — filing an issue against text that isn't present here would be
inaccurate.

## Plan

### Step 0 — Kernel test harness that exercises the real conditions validator

Create `tests/src/Kernel/WebformRankingKernelTestBase.php`, extending
`KernelTestBase`, `$modules = ['system', 'user', 'path_alias', 'webform', 'webform_ranking']`,
installing the `user`/`path_alias`/`webform_submission` entity schemas and the
`webform` schema/config (per the shipped kernel tests' module list plus what
`WebformSubmissionForm` needs at runtime).

Reuse rather than reinvent:

- Pull in `Drupal\Tests\webform\Traits\WebformBrowserTestTrait` (or copy just
  its `createWebform($values, $elements, $settings)` method if the trait pulls
  in browser-only dependencies) — it already builds a `Webform` entity from a
  plain elements array via `Webform::create()` + `Yaml::encode()`. Don't
  hand-roll a second version of this.
- Submit through `Drupal\webform\WebformSubmissionForm::submitWebformSubmission(WebformSubmissionInterface $webform_submission, $validate_only = FALSE)`
  (`web/modules/contrib/webform/src/WebformSubmissionForm.php:3344`) — confirmed
  public/static, confirmed it returns the form errors array on failure or the
  saved submission entity on success. This is the real production entry point,
  not a reimplementation.

Add one throwaway-style smoke test in this same file's own test (or a small
sibling test) that builds a two-item ranking webform, submits a matrix
ranking, and asserts stored data equals the expected flat rank map — proving
the harness itself works before it's relied on in Step 1.

### Step 1 — B1: fix `WebformRankingVisibilityResolver`

**File:** `src/WebformRankingVisibilityResolver.php`

Replace the `validateConditions()` call with a per-state-key loop calling
`validateState()`:

```php
public function resolveVisibleItemValues(array $items, ?WebformSubmissionInterface $webform_submission): array {
  $visible = [];
  foreach ($items as $item) {
    if (empty($item['states'])) {
      $visible[] = $item['value'];
      continue;
    }
    if (!$webform_submission) {
      // No submission context: fail closed (unchanged).
      continue;
    }
    if ($this->isVisible($item['value'], $item['states'], $webform_submission)) {
      $visible[] = $item['value'];
    }
  }
  return $visible;
}

protected function isVisible(string $item_value, array $states, WebformSubmissionInterface $submission): bool {
  foreach ($states as $state => $conditions) {
    [$base] = explode('-', ltrim((string) $state, '!'), 2);
    if (!in_array($base, ['visible', 'invisible'], TRUE)) {
      // 'required'/'enabled'/etc. don't govern item inclusion.
      continue;
    }
    $result = $this->conditionsValidator->validateState($state, $conditions, $submission);
    if ($result === NULL) {
      // Unresolvable selector (admin typo): fail OPEN, log it.
      $this->logger->warning('Webform Ranking item %item: could not resolve #states condition (state: %state) — treating as visible.', [
        '%item' => $item_value,
        '%state' => $state,
      ]);
      continue;
    }
    if (!$result) {
      return FALSE;
    }
  }
  return TRUE;
}
```

Inject `\Drupal\Core\Logger\LoggerChannelFactoryInterface` (or the
`logger.channel.webform_ranking` channel, matching how other Webform-adjacent
services get their logger) via the constructor and update
`webform_ranking.services.yml` accordingly.

Rewrite the class docblock — it currently asserts the `validateConditions()`
call shape is correct and argues for uniform fail-closed; both are now wrong.
Document: unconditional items always visible; no-submission-context fails
closed; a resolvable `visible`/`invisible` state governs inclusion; an
unresolvable selector fails open with a logged warning.

**Also in this step — H4:**

- Rewrite `tests/src/Unit/WebformRankingVisibilityResolverTest.php`: every
  mock currently expects `validateConditions()` — change to `validateState()`
  with the correct 3-arg shape (`$state`, `$conditions`, `$submission`), and
  add a case for `willReturn(NULL)` asserting fail-open + a logged warning
  (mock the logger channel too). Keep the existing no-context and no-states
  tests; they're still correct.
- Add `tests/src/Kernel/WebformRankingConditionalItemTest.php` on the Step 0
  base. Cover the four-quadrant table from the fix plan (visible/invisible ×
  trigger-satisfied/not) against the **real** conditions validator, plus the
  documented regression: condition satisfied, conditional item ranked 1st,
  normal item ranked 2nd → must submit cleanly (today it produces the "ranks
  must start from the top" error).

### Step 2 — B2: stop a scalar `#default_value` from fataling the form

**File:** `src/Plugin/WebformElement/WebformRanking.php`

- **2a (root cause):** in `defineDefaultProperties()`, `unset()` the
  `default_value` key after merging with `parent::defineDefaultProperties()`,
  so Webform's element config form stops offering the "Default value"
  textfield. Verify `hasProperty('default_value')` then returns FALSE.
- **2b (defence in depth):** in `prepare()` (currently line ~322-325), replace
  the one-sided `is_array()` guard:

```php
$element['#default_value'] = is_array($element['#default_value'] ?? NULL)
  ? WebformRankingConverter::matrixToCanonical($element['#default_value'])
  : ['values' => [], 'na' => []];
```

This also fixes the current `!empty()` short-circuit, which leaves a bare
`[]` default value un-normalized to canonical shape.

**Verification:** new Step-0-based kernel test — a webform whose ranking
element has `'#default_value' => 'oops'` builds/renders without throwing.

### Step 3 — B3: make `#required` work

**File:** `src/Element/WebformRanking.php`, in `validateWebformRanking()`

Add, after the visibility-filtering/stale-entry-drop block and before the
`#required_all` check:

```php
if (!empty($element['#required']) && !$values && !$na) {
  $form_state->setError($element, $element['#required_error']
    ?? $translation->translate('@title field is required.', ['@title' => $title]));
  return;
}
```

Ordering matters: must run after `$values`/`$na` are filtered to
currently-visible items, so a submission consisting only of hidden items still
counts as empty.

**Verification:** Kernel tests covering the 4-row matrix from the fix plan
(`#required` × `#required_all` × empty/partial value).

### Step 4 — H3: delete the dead/hazardous config schema

Delete `config/schema/webform_ranking.schema.yml` entirely. Confirmed nothing
reads it (Webform stores element config as a raw YAML text blob) and its
parent type doesn't exist. Verify: a `KernelTestBase` run with
`$strictConfigSchema = TRUE` (the existing kernel tests already run under
core's default strict schema checking) still passes after removal, and no
other file references this schema.

## Verification (end-to-end, for this whole pass)

1. `phpunit` on Unit + Kernel suites — all existing tests plus the new ones
   pass, run via DDEV (`ddev exec phpunit` or the project's documented test
   command in `docs/DEVELOPMENT.md`/`docs/TESTING.md`).
2. `phpcs --standard=Drupal,DrupalPractice` stays clean on touched files.
3. Manually confirm the regression scenario from B1 (conditional item
   ranked 1st with its trigger satisfied) submits cleanly via the new
   Step-0-based kernel test — this is the concrete bug the review reproduced.
4. Confirm `hasProperty('default_value')` is FALSE and a hand-crafted string
   `#default_value` no longer throws (Step 2 test).
5. Confirm an empty required ranking now produces a validation error
   (Step 3 test).
6. Confirm no PHP file (`src/`, `tests/`) or YAML file still references
   `config/schema/webform_ranking.schema.yml` after deletion.

Follow the repo's existing branch-naming convention (`bugfix/...`) for this
work, consistent with the existing history (`bugfix/dragdrop-pointer-reorder`,
`bugfix/items-admin-states-toggle`, `bugfix/per-item-conditional-visibility`).
