<?php

namespace Drupal\Tests\webform_ranking\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;
use Drupal\webform\WebformInterface;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform_ranking\Element\WebformRanking as WebformRankingElement;

/**
 * Base class for kernel tests validating against a real conditions validator.
 *
 * Unlike WebformRankingValidationKernelTest (which validates the fail-closed,
 * no-submission-context path with a dummy, non-Webform form object) and
 * WebformRankingPipelineTest (which is a pipeline smoke test with no
 * submission context at all), tests on this base build a real Webform +
 * WebformSubmission entity and validate against a real WebformSubmissionForm
 * — the only way to exercise WebformRankingVisibilityResolver's true/false
 * evaluation path through the real webform_submission.conditions_validator
 * service, rather than a mock.
 *
 * validateWebformRanking() is called directly with a hand-built #value,
 * matching the existing kernel tests' own approach (see their docblocks for
 * why: it sidesteps FormBuilder's input-processing plumbing, which doesn't
 * need to be exercised to test this element's own validation logic — only
 * a real WebformSubmissionInterface, backed by a real conditions validator,
 * needs to be real here).
 */
abstract class WebformRankingKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'path_alias',
    'webform',
    'webform_ranking',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('webform_submission');
    $this->installSchema('webform', ['webform']);
    $this->installConfig(['webform']);
  }

  /**
   * Builds and saves a webform with the given elements.
   *
   * @param string $id
   *   The webform machine name.
   * @param array $elements
   *   The webform's elements, keyed by machine name.
   *
   * @return \Drupal\webform\WebformInterface
   *   The saved webform.
   */
  protected function createWebformWithElements(string $id, array $elements): WebformInterface {
    $webform = Webform::create([
      'id' => $id,
      'title' => $id,
      'elements' => Yaml::encode($elements),
    ]);
    $webform->save();

    return $webform;
  }

  /**
   * Builds and saves a real WebformSubmission carrying $data.
   *
   * @param \Drupal\webform\WebformInterface $webform
   *   The webform.
   * @param array $data
   *   Submission element data, e.g. ['trigger' => 'yes'] — used by the
   *   real webform_submission.conditions_validator service to resolve
   *   #states conditions against this submission.
   *
   * @return \Drupal\webform\WebformSubmissionInterface
   *   The saved submission.
   */
  protected function createRealSubmission(WebformInterface $webform, array $data = []): WebformSubmissionInterface {
    $webform_submission = WebformSubmission::create([
      'webform_id' => $webform->id(),
      'data' => $data,
    ]);
    $webform_submission->save();

    return $webform_submission;
  }

  /**
   * Runs validateWebformRanking() against a real submission's form object.
   *
   * Mirrors WebformRankingValidationKernelTest::validate(), but attaches a
   * real WebformSubmissionForm wrapping $webform_submission as the
   * FormState's form object — instead of a dummy, non-Webform form — so
   * `$form_object instanceof \Drupal\webform\WebformSubmissionForm` is TRUE
   * and the resolver evaluates #states against the real, saved submission
   * data via the real conditions validator service.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   A real, saved submission (see createRealSubmission()) whose webform
   *   defines the 'ranking' element being validated.
   * @param array $overrides
   *   Properties to merge over the default 'ranking' element definition
   *   (#items, #allow_na, #required, #required_all, etc.).
   * @param array $value
   *   The canonical ['values' => [...], 'na' => [...]] to place on #value.
   *
   * @return \Drupal\Core\Form\FormState
   *   The FormState after validation, for asserting on errors/values.
   */
  protected function validateAgainstRealSubmission(WebformSubmissionInterface $webform_submission, array $overrides, array $value): FormState {
    $element = $overrides + [
      '#title' => 'Ranking',
      '#allow_na' => FALSE,
      '#required_all' => FALSE,
      '#parents' => ['ranking'],
      '#value' => $value,
    ];

    /** @var \Drupal\webform\WebformSubmissionForm $form_object */
    $form_object = \Drupal::entityTypeManager()->getFormObject('webform_submission', 'api');
    $form_object->setEntity($webform_submission);

    $form_state = new FormState();
    $form_state->setFormObject($form_object);

    $complete_form = [];
    WebformRankingElement::validateWebformRanking($element, $form_state, $complete_form);

    return $form_state;
  }

  /**
   * Runs raw matrix input through the real valueCallback(), then validates.
   *
   * Unlike validateAgainstRealSubmission() (which takes an already-canonical
   * #value), this drives raw per-item rank input through the real
   * WebformRankingElement::valueCallback() first — the only way to populate
   * #_matrix_raw_input, which matrixRanksAreSequential()'s "no gaps" check
   * reads (see WebformRankingConverter::matrixRanksAreSequential()'s
   * docblock). Needed to reproduce the exact reported regression: a
   * conditionally-visible item ranked 1st must not trigger a false "ranks
   * must start from the top" error once its condition is satisfied.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   A real, saved submission whose webform defines the 'ranking' element
   *   being validated.
   * @param array $overrides
   *   Properties to merge over the default 'ranking' element definition.
   * @param array $matrix_input
   *   Raw matrix input, item value => rank string ('1', '2', ..., 'na').
   *
   * @return \Drupal\Core\Form\FormState
   *   The FormState after validation, for asserting on errors/values.
   */
  protected function validateMatrixInputAgainstRealSubmission(WebformSubmissionInterface $webform_submission, array $overrides, array $matrix_input): FormState {
    $element = $overrides + [
      '#title' => 'Ranking',
      '#allow_na' => FALSE,
      '#required_all' => FALSE,
      '#parents' => ['ranking'],
    ];

    /** @var \Drupal\webform\WebformSubmissionForm $form_object */
    $form_object = \Drupal::entityTypeManager()->getFormObject('webform_submission', 'api');
    $form_object->setEntity($webform_submission);

    $form_state = new FormState();
    $form_state->setFormObject($form_object);

    $element['#value'] = WebformRankingElement::valueCallback($element, ['matrix' => $matrix_input], $form_state);

    $complete_form = [];
    WebformRankingElement::validateWebformRanking($element, $form_state, $complete_form);

    return $form_state;
  }

}
