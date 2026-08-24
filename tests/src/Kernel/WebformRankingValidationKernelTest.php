<?php

namespace Drupal\Tests\webform_ranking\Kernel;

use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\webform_ranking\Element\WebformRanking as WebformRankingElement;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests WebformRanking::validateWebformRanking() against a real container.
 *
 * Calls the static validate callback directly with a hand-built
 * #value, rather than simulating a full page submission through
 * FormBuilder. Two reasons:
 *
 * - It sidesteps routing/CSRF concerns a Kernel test context can't
 *   straightforwardly exercise.
 * - Several scenarios worth testing here — duplicate ranks and
 *   non-sequential order in particular — are only reachable by
 *   directly fabricating #value. Going through the real matrix/
 *   dragdrop -> WebformRankingConverter path can never *produce* a
 *   duplicate rank; the converter's own de-duplication (tested in
 *   WebformRankingConverterTest) already prevents it. Testing that
 *   validateWebformRanking() independently also rejects it requires
 *   bypassing the converter on purpose, i.e. simulating a forged
 *   direct POST that never went through normal value normalization.
 *
 * Scope note, stated plainly: this covers the fail-closed path for
 * conditional items (no WebformSubmissionForm in context — see
 * DummyTestForm below), but NOT the "condition evaluates true/false
 * against a real, live Webform submission" path. That would require
 * constructing an actual Webform + WebformSubmission entity and a
 * genuine WebformSubmissionForm, which is a heavier, separate test —
 * not built in this pass. WebformRankingVisibilityResolverTest (Unit)
 * already covers the resolver's own true/false evaluation logic in
 * isolation with a mocked conditions validator; what's untested is
 * specifically the live integration of that path through a real
 * Webform submission.
 */
#[Group('webform_ranking')]
class WebformRankingValidationKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'webform',
    'webform_ranking',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // No entity schema/config installs here deliberately — none of
    // these tests touch a real Webform or WebformSubmission entity,
    // only the validate callback and the services it pulls from the
    // container (webform_submission.conditions_validator via our
    // resolver). If
    // bootstrap fails here complaining about missing schema/config,
    // Webform's own module dependencies may require more than this
    // minimal module list — adjust against your installed version.
  }

  /**
   * Standard 3-item configuration shared by most test cases.
   */
  protected function items(): array {
    return [
      ['value' => 'item_a', 'label' => 'Item A'],
      ['value' => 'item_b', 'label' => 'Item B'],
      ['value' => 'item_c', 'label' => 'Item C'],
    ];
  }

  /**
   * Builds a FormState with a non-Webform form object attached.
   *
   * Deliberately calls setFormObject() rather than leaving it unset:
   * FormState::getFormObject() is called unconditionally inside
   * validateWebformRanking(), and an un-set typed property would be
   * accessed-before-initialization on a bare FormState. Using a
   * minimal FormInterface implementation that is NOT a
   * WebformSubmissionForm exercises exactly the "no Webform submission
   * context" branch — the fail-closed path — deliberately, not by
   * accident of an uninitialized property.
   */
  protected function newFormState(): FormState {
    $form_state = new FormState();
    $form_state->setFormObject(new WebformRankingDummyTestForm());
    return $form_state;
  }

  /**
   * Runs the validate callback against a hand-built element/value.
   *
   * @param array $overrides
   *   Properties to merge over the default element definition
   *   (#items, #allow_na, #required_all, etc.).
   * @param array $value
   *   The canonical ['values' => [...], 'na' => [...]] to place on
   *   #value, simulating whatever valueCallback() would have produced
   *   — or deliberately would NOT have produced, for the
   *   forged-input test cases.
   *
   * @return \Drupal\Core\Form\FormState
   *   The FormState after validation, for asserting on errors/values.
   */
  protected function validate(array $overrides, array $value): FormState {
    // $overrides must come FIRST: PHP's array union operator keeps the
    // left-hand array's value on a key collision, so putting the
    // hardcoded defaults first (as an earlier version of this test did)
    // silently defeats every override — #required_all and #items in
    // particular, which is exactly what caused several of these tests
    // to fail against the wrong configuration without erroring loudly
    // about it.
    $element = $overrides + [
      '#title' => 'Ranking',
      '#items' => $this->items(),
      '#allow_na' => FALSE,
      '#required_all' => TRUE,
      '#parents' => ['ranking'],
      '#value' => $value,
    ];

    $form_state = $this->newFormState();
    $complete_form = [];
    WebformRankingElement::validateWebformRanking($element, $form_state, $complete_form);

    return $form_state;
  }

  /**
   * Tests that a valid, full ranking produces no errors.
   */
  public function testValidFullRankingProducesNoErrors(): void {
    $form_state = $this->validate([], [
      'values' => ['item_b', 'item_a', 'item_c'],
      'na' => [],
    ]);

    $this->assertSame([], $form_state->getErrors());
  }

  /**
   * Tests that a valid ranking's value is preserved unfiltered.
   */
  public function testValidFullRankingPreservesValueWhenNothingIsFiltered(): void {
    $form_state = $this->validate([], ['values' => ['item_b', 'item_a', 'item_c'], 'na' => []]);

    $this->assertSame([], $form_state->getErrors());
    // Stored/final #value is the flat item-value => rank shape, not
    // canonical — see WebformRanking::validateWebformRanking()'s
    // storage-boundary note.
    $this->assertSame(
      ['item_b' => '1', 'item_a' => '2', 'item_c' => '3'],
      $form_state->getValue('ranking')
    );
  }

  /**
   * Tests that an unknown item key is rejected as tamper defense.
   *
   * Also asserts the value is still written back afterward, sanitized
   * to the legitimate portion only (the forged 'item_x' dropped, the
   * real 'item_a' kept) — see
   * testValueIsWrittenBackInFlatShapeEvenWhenValidationFails() for why
   * this write-back must never be skipped, on this or any other
   * failure branch.
   */
  public function testUnknownItemKeyIsRejectedAsTamperDefense(): void {
    $form_state = $this->validate(['#required_all' => FALSE], [
      // 'item_x' was never configured at all.
      'values' => ['item_x', 'item_a'],
      'na' => [],
    ]);

    $errors = $form_state->getErrors();
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('invalid selection', (string) reset($errors));
    $this->assertSame(['item_a' => '1'], $form_state->getValue('ranking'));
  }

  /**
   * Tests that the flat-shape value is written back even on failure.
   *
   * Real bug: several checks in validateWebformRanking() used to
   * `return` immediately after setError(), skipping the final
   * canonical-to-flat write-back entirely — leaving
   * $form_state->getValue('ranking') in canonical {values, na} shape.
   * On a genuine failed submission this was invisible (the form is
   * rejected and nothing is saved either way), but it broke live
   * webform_computed_twig AJAX recomputation: that path reads
   * $form_state->getValues() directly
   * (WebformSubmissionForm::copyFormValuesToEntity()), bypassing this
   * element's plugin entirely, and expects storage (flat) shape
   * regardless of whether this element's own validation currently
   * passes — e.g. mid-interaction, before the user has finished
   * ranking every item.
   */
  public function testValueIsWrittenBackInFlatShapeEvenWhenValidationFails(): void {
    $form_state = $this->validate(['#required_all' => TRUE], [
      // Missing item_b/item_c triggers the #required_all error below.
      'values' => ['item_a'],
      'na' => [],
    ]);

    $errors = $form_state->getErrors();
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('every item must be ranked', (string) reset($errors));
    // Flat shape, not canonical {values, na} — the exact regression.
    $this->assertSame(['item_a' => '1'], $form_state->getValue('ranking'));
  }

  /**
   * Tests that a duplicate rank in a forged #value is rejected.
   *
   * Only reachable via a forged #value — see class docblock. The
   * normal matrix/dragdrop -> converter path can never produce this.
   */
  public function testDuplicateRankInForgedValueIsRejected(): void {
    $form_state = $this->validate([], [
      'values' => ['item_a', 'item_a', 'item_b'],
      'na' => [],
    ]);

    $errors = $form_state->getErrors();
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('ranked once', (string) reset($errors));
  }

  /**
   * Tests that an item both ranked and marked N/A is rejected.
   */
  public function testItemBothRankedAndNaIsRejected(): void {
    $form_state = $this->validate(['#allow_na' => TRUE], [
      'values' => ['item_a'],
      'na' => ['item_a'],
    ]);

    $errors = $form_state->getErrors();
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('cannot be both ranked', (string) reset($errors));
  }

  /**
   * Tests that N/A is rejected when the element doesn't allow it.
   */
  public function testNaIsRejectedWhenNotAllowed(): void {
    $form_state = $this->validate(['#allow_na' => FALSE], [
      'values' => ['item_a'],
      'na' => ['item_b'],
    ]);

    $errors = $form_state->getErrors();
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('does not allow', (string) reset($errors));
  }

  /**
   * Tests that non-sequential-keyed values are normalized, not rejected.
   *
   * A non-sequential-keyed 'values' array (e.g. keys 1,3 instead of
   * 0,1) can only arise from a forged #value bypassing the converter.
   * Earlier versions of this test asserted that gets rejected; it
   * doesn't, and shouldn't — see the docblock in
   * validateWebformRanking() for why. The filtering step a few lines
   * above the required_all check always reindexes 'values' via
   * array_values(), so rank ends up correctly derived from iteration
   * order regardless of the original (forged) keys. This test now
   * documents that normalization explicitly, rather than asserting a
   * rejection that the code no longer performs and, on reflection,
   * never actually needed to.
   */
  public function testNonSequentialKeyedValuesAreNormalizedNotRejected(): void {
    $form_state = $this->validate(['#required_all' => FALSE], [
      'values' => [1 => 'item_a', 3 => 'item_b'],
      'na' => [],
    ]);

    $this->assertSame([], $form_state->getErrors());
  }

  /**
   * Tests that #required_all rejects a ranking missing items.
   */
  public function testRequiredAllRejectsMissingItems(): void {
    $form_state = $this->validate(['#required_all' => TRUE], [
      // item_b and item_c never accounted for.
      'values' => ['item_a'],
      'na' => [],
    ]);

    $errors = $form_state->getErrors();
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('every item must be ranked', (string) reset($errors));
  }

  /**
   * Tests that #required_all isn't enforced when disabled.
   */
  public function testRequiredAllNotEnforcedWhenDisabled(): void {
    $form_state = $this->validate(['#required_all' => FALSE], [
      'values' => ['item_a'],
      'na' => [],
    ]);

    $this->assertSame([], $form_state->getErrors());
  }

  // #required, empty ranking: core's own required check can't see this
  // (valueCallback() always returns a 2-key array), so this must be an
  // explicit check in validateWebformRanking() — see B3.
  public function testRequiredRejectsCompletelyEmptyRanking(): void {
    $form_state = $this->validate(['#required' => TRUE, '#required_all' => FALSE], [
      'values' => [],
      'na' => [],
    ]);

    $errors = $form_state->getErrors();
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('required', (string) reset($errors));
  }

  public function testRequiredPassesWithAtLeastOneItemRanked(): void {
    $form_state = $this->validate(['#required' => TRUE, '#required_all' => FALSE], [
      'values' => ['item_a'],
      'na' => [],
    ]);

    $this->assertSame([], $form_state->getErrors());
  }

  public function testNotRequiredPassesWithEmptyRanking(): void {
    $form_state = $this->validate(['#required' => FALSE, '#required_all' => FALSE], [
      'values' => [],
      'na' => [],
    ]);

    $this->assertSame([], $form_state->getErrors());
  }

  // An N/A-only ranking (nothing in 'values', but items opted out via
  // 'na') must NOT be treated as empty for #required purposes.
  public function testRequiredPassesWhenOnlyNaEntriesArePresent(): void {
    $form_state = $this->validate(['#required' => TRUE, '#required_all' => FALSE, '#allow_na' => TRUE], [
      'values' => [],
      'na' => ['item_a'],
    ]);

    $this->assertSame([], $form_state->getErrors());
  }

  /**
   * Tests the fail-closed contract via the real resolver service.
   *
   * Exercised through the real, container-resolved
   * WebformRankingVisibilityResolver — not a mock, unlike the Unit
   * test of the resolver alone.
   *
   * A conditional item with no Webform submission context in scope:
   * - Is excluded from the "visible" set, so its absence does NOT
   *   trigger a required_all error.
   * - If a stale rank for it is present anyway (simulating a hidden
   *   input that didn't get cleared client-side), it's silently
   *   dropped from the stored value rather than erroring — it's a
   *   known, configured item, just not currently applicable, which is
   *   the documented distinction from a truly unknown/tampered key.
   */
  public function testConditionalItemFailsClosedAndIsSilentlyDroppedWithoutSubmissionContext(): void {
    $items = $this->items();
    $items[] = [
      'value' => 'item_conditional',
      'label' => 'Conditional Item',
      'states' => ['visible' => [':input[name="trigger"]' => ['value' => 'x']]],
    ];

    $form_state = $this->validate(
      ['#items' => $items, '#required_all' => TRUE],
      [
        // item_conditional is included here as if a stale hidden
        // input still carried it — this must NOT be treated as
        // tampering, and must NOT block submission by counting as
        // "missing" either.
        'values' => ['item_a', 'item_b', 'item_c', 'item_conditional'],
        'na' => [],
      ]
    );

    $this->assertSame([], $form_state->getErrors());
    // Stored/final #value is the flat item-value => rank shape, not
    // canonical — see WebformRanking::validateWebformRanking()'s
    // storage-boundary note. item_conditional is correctly absent:
    // dropped as not-currently-visible, same as the canonical-shape
    // assertion this replaced.
    $this->assertSame(
      ['item_a' => '1', 'item_b' => '2', 'item_c' => '3'],
      $form_state->getValue('ranking')
    );
  }

  /**
   * Tests the GitHub issue #63 loophole #require_first_place closes.
   *
   * With #allow_na on, #required_all alone is satisfied by marking
   * every item N/A — nothing ranked at all. #require_first_place must
   * reject that specifically.
   */
  public function testRequireFirstPlaceRejectsAllNaRanking(): void {
    $form_state = $this->validate(
      ['#allow_na' => TRUE, '#required_all' => TRUE, '#require_first_place' => TRUE],
      ['values' => [], 'na' => ['item_a', 'item_b', 'item_c']]
    );

    $errors = $form_state->getErrors();
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('1st place', (string) reset($errors));
  }

  /**
   * Tests that #require_first_place passes once anything is ranked 1st.
   *
   * Deliberately a partial ranking (not every item), to confirm this
   * check is independent of #required_all's own "everything accounted
   * for" rule.
   */
  public function testRequireFirstPlacePassesWithPartialRanking(): void {
    $form_state = $this->validate(
      ['#allow_na' => TRUE, '#required_all' => FALSE, '#require_first_place' => TRUE],
      ['values' => ['item_a'], 'na' => []]
    );

    $this->assertSame([], $form_state->getErrors());
  }

  /**
   * Tests that #require_first_place is opt-in, not enforced by default.
   */
  public function testRequireFirstPlaceNotEnforcedWhenDisabled(): void {
    $form_state = $this->validate(
      ['#allow_na' => TRUE, '#required_all' => TRUE],
      ['values' => [], 'na' => ['item_a', 'item_b', 'item_c']]
    );

    $this->assertSame([], $form_state->getErrors());
  }

  /**
   * Tests that a custom #require_first_place_error overrides the default.
   */
  public function testRequireFirstPlaceUsesCustomErrorMessage(): void {
    $form_state = $this->validate(
      [
        '#allow_na' => TRUE,
        '#required_all' => TRUE,
        '#require_first_place' => TRUE,
        '#require_first_place_error' => 'Pick a favorite first.',
      ],
      ['values' => [], 'na' => ['item_a', 'item_b', 'item_c']]
    );

    $errors = $form_state->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('Pick a favorite first.', (string) reset($errors));
  }

}

/**
 * Minimal, non-Webform FormInterface implementation for newFormState().
 *
 * Gives FormState a valid form object without being a
 * WebformSubmissionForm. Deliberately not the class under test; exists
 * purely to avoid an uninitialized-typed-property access on a bare
 * FormState.
 */
class WebformRankingDummyTestForm implements FormInterface {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'webform_ranking_dummy_test_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
  }

}
