<?php

namespace Drupal\Tests\webform_ranking\Kernel;

use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * End-to-end pipeline smoke test: real matrix input through the actual
 * Form API #value_callback -> #process -> #element_validate chain, via
 * \Drupal::formBuilder()->submitForm().
 *
 * Deliberately narrow scope: WebformRankingValidationKernelTest (in
 * this same directory) calls validateWebformRanking() directly with
 * hand-built #value arrays and is the comprehensive suite for the
 * validator's own rules — including forged-input scenarios (duplicate
 * ranks, non-sequential order) that the real matrix -> converter path
 * can never produce in the first place, so testing them there requires
 * bypassing the pipeline on purpose. This file exists only to confirm
 * that pipeline itself is actually wired correctly end-to-end — that
 * raw matrix POST data really does flow through
 * WebformRankingConverter::matrixToCanonical() and out the other side
 * as a validated (or correctly rejected) canonical value — which a
 * validator-only test, however thorough, can't confirm on its own.
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
   * Submits a webform_ranking element with real matrix POST input
   * through the actual Form API pipeline.
   *
   * @return string[]
   *   Form errors keyed by element name.
   */
  protected function submitAndGetErrors(array $element_properties, array $matrix_input): array {
    $element = [
      '#type' => 'webform_ranking',
      '#title' => 'Ranking',
    ] + $element_properties;

    $form_object = new WebformRankingTestForm($element);
    $form_state = new FormState();
    // Without this, FormBuilder only treats setUserInput() as a real
    // submission when the form's expected method matches the *actual*
    // current HTTP request method — and a Kernel test's synthetic
    // request context isn't a POST, so input silently never gets
    // processed and the element falls back to #default_value instead.
    // setProgrammed(TRUE) is the standard way to tell FormBuilder "trust
    // the user input I've provided, regardless of the ambient request."
    $form_state->setProgrammed(TRUE);
    $form_state->setUserInput([
      'ranking' => [
        'matrix' => $matrix_input,
      ],
      'op' => 'Submit',
    ]);

    \Drupal::formBuilder()->submitForm($form_object, $form_state);

    return $form_state->getErrors();
  }

  // Confirms the happy path survives the real pipeline: raw matrix
  // input -> valueCallback() -> matrixToCanonical() -> validate -> no
  // errors. Rule-level edge cases belong in
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

  // Confirms tamper defense holds when the unknown key arrives via a
  // real (forged) matrix POST body, not just a hand-fed #value — i.e.
  // that matrixToCanonical() correctly carries an unconfigured item
  // key through rather than silently discarding it, and that the
  // validator then rejects it.
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

}

/**
 * Minimal FormInterface wrapper so a single render element can be run
 * through the real Form API pipeline via
 * \Drupal::formBuilder()->submitForm(), without needing a full Webform
 * entity or WebformSubmissionForm.
 */
class WebformRankingTestForm implements FormInterface {

  /**
   * @var array
   */
  protected $element;

  public function __construct(array $element) {
    $this->element = $element;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'webform_ranking_test_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['ranking'] = $this->element;
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => 'Submit',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {}

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {}

}
