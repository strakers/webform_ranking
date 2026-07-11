<?php

namespace Drupal\Tests\webform_ranking\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\webform_ranking\Element\WebformRanking as WebformRankingElement;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pipeline smoke test: real matrix input through the actual
 * valueCallback() -> matrixToCanonical() -> validateWebformRanking()
 * chain.
 *
 * Deliberately narrow scope: WebformRankingValidationKernelTest (in
 * this same directory) calls validateWebformRanking() directly with
 * hand-built #value arrays and is the comprehensive suite for the
 * validator's own rules. This file exists only to confirm that raw
 * matrix POST-shaped input really does flow through
 * WebformRankingConverter::matrixToCanonical() and out the other side
 * as a validated (or correctly rejected) canonical value — which a
 * validator-only test, however thorough, can't confirm on its own.
 *
 * Revision note, stated plainly: an earlier version of this test drove
 * the element through \Drupal::formBuilder()->submitForm() for genuine
 * end-to-end coverage of #value_callback -> #process ->
 * #element_validate together. That kept failing because FormBuilder's
 * logic for deciding whether setUserInput() counts as a "real"
 * submission (tied to matching the form's expected method against the
 * *actual* current request in the Kernel test environment) needed more
 * precise, version-specific knowledge than could be reliably
 * reconstructed without executing it — two different guesses at fixing
 * it were both wrong. Rather than keep guessing at Form API plumbing
 * this test doesn't actually need to exercise, this version calls
 * valueCallback() directly with raw matrix input (still the real
 * production method, not reimplemented), which tests the same
 * conversion behavior without depending on FormBuilder's submission
 * detection at all. The trade-off, stated explicitly: this no longer
 * proves #process wiring (building the matrix sub-elements) works
 * end-to-end — only that valueCallback() and validateWebformRanking()
 * compose correctly. #process is exercised implicitly any time the
 * element actually renders, but isn't covered by an automated test
 * anywhere in this suite; a Functional/Nightwatch test would be the
 * place for that, not a Kernel test.
 */
#[Group('webform_ranking')]
class WebformRankingValidationTest extends KernelTestBase {

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
    $this->installEntitySchema('user');
  }

  protected function baseItems(): array {
    return [
      ['value' => 'item_a', 'label' => 'Item A'],
      ['value' => 'item_b', 'label' => 'Item B'],
      ['value' => 'item_c', 'label' => 'Item C'],
    ];
  }

  /**
   * Runs raw matrix input through the real valueCallback(), then the
   * real validateWebformRanking(), and returns the resulting errors.
   *
   * @return string[]
   *   Form errors keyed by element name.
   */
  protected function submitAndGetErrors(array $element_properties, array $matrix_input): array {
    $element = [
      '#type' => 'webform_ranking',
      '#title' => 'Ranking',
      '#parents' => ['ranking'],
    ] + $element_properties;

    $form_state = new FormState();
    // FormState's internal error-handling code reads
    // buildInfo['callback_object'] directly. A real form build (via
    // FormBuilder) always populates this; since this test deliberately
    // bypasses FormBuilder (see class docblock), it's never set here,
    // which PHP 8.1+'s stricter undefined-array-key diagnostics flag as
    // a warning. Pre-seeding it via FormState's own public API is
    // simpler and more reliable than reconstructing more of
    // FormBuilder's build process just to satisfy this.
    $form_state->addBuildInfo('callback_object', NULL);

    // The real, production value callback — not a reimplementation —
    // given the same shape of raw input FormBuilder would hand it for
    // a submitted matrix.
    $element['#value'] = WebformRankingElement::valueCallback($element, ['matrix' => $matrix_input], $form_state);

    $complete_form = [];
    WebformRankingElement::validateWebformRanking($element, $form_state, $complete_form);

    return $form_state->getErrors();
  }

  // Confirms the happy path survives the real conversion + validation
  // chain: raw matrix input -> valueCallback() -> matrixToCanonical()
  // -> validate -> no errors. Rule-level edge cases belong in
  // WebformRankingValidationKernelTest, not duplicated here.
  public function testFullyRankedSubmissionPassesValidationEndToEnd(): void {
    $errors = $this->submitAndGetErrors(
      [
        '#items' => $this->baseItems(),
        '#allow_na' => FALSE,
        '#required_all' => TRUE,
      ],
      ['item_a' => '1', 'item_b' => '2', 'item_c' => '3']
    );

    $this->assertSame([], $errors);
  }

  // Confirms tamper defense holds when the unknown key arrives via raw
  // matrix input, not just a hand-fed #value — i.e. that
  // matrixToCanonical() correctly carries an unconfigured item key
  // through rather than silently discarding it, and that the validator
  // then rejects it.
  public function testUnknownItemKeyIsRejectedEndToEnd(): void {
    $errors = $this->submitAndGetErrors(
      [
        '#items' => $this->baseItems(),
        '#allow_na' => FALSE,
        '#required_all' => FALSE,
      ],
      ['item_a' => '1', 'item_x_never_configured' => '2']
    );

    $this->assertNotEmpty($errors);
  }

  // The exact reported bug this rule fixes: item_b/item_c ranked 2nd
  // and 3rd, item_a marked N/A — every item is accounted for
  // (required_all would be satisfied), but nothing is ranked 1st.
  // Only reachable end-to-end (not via a hand-built #value) because
  // the check reads the raw per-item input valueCallback() stashes —
  // see WebformRankingConverter::matrixRanksAreSequential()'s docblock
  // for why canonical shape alone can't detect this.
  public function testSkippedFirstRankIsRejectedEndToEnd(): void {
    $errors = $this->submitAndGetErrors(
      [
        '#items' => $this->baseItems(),
        '#allow_na' => TRUE,
        '#required_all' => TRUE,
      ],
      ['item_a' => 'na', 'item_b' => '2', 'item_c' => '3']
    );

    $this->assertNotEmpty($errors);
  }

  // A gap in the *middle* (1st and 3rd used, 2nd skipped) must be
  // rejected the same way as a skipped 1st place.
  public function testGapInMiddleRankIsRejectedEndToEnd(): void {
    $errors = $this->submitAndGetErrors(
      [
        '#items' => $this->baseItems(),
        '#allow_na' => TRUE,
        '#required_all' => TRUE,
      ],
      ['item_a' => '1', 'item_b' => 'na', 'item_c' => '3']
    );

    $this->assertNotEmpty($errors);
  }

  // A genuinely partial-but-sequential ranking (1st and 2nd used, 3rd
  // left N/A) must still pass — the rule only rejects *skipped*
  // leading ranks, not partial rankings that start from the top.
  public function testPartialButSequentialRankingPassesEndToEnd(): void {
    $errors = $this->submitAndGetErrors(
      [
        '#items' => $this->baseItems(),
        '#allow_na' => TRUE,
        '#required_all' => TRUE,
      ],
      ['item_a' => '1', 'item_b' => '2', 'item_c' => 'na']
    );

    $this->assertSame([], $errors);
  }

}
