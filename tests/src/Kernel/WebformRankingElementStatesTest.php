<?php

namespace Drupal\Tests\webform_ranking\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\webform\Entity\WebformSubmission;
use Drupal\webform\WebformInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests server-side behavior of element-level '#states' — GitHub issue #57.
 *
 * The client-side "does the whole field actually hide/show in a real
 * browser" behavior is covered by
 * WebformRankingElementStatesJavaScriptTest; this covers the server-side
 * half of the same acceptance criteria: a hidden element's submitted value
 * must not leak into storage.
 *
 * Deliberately drives a real \Drupal::formBuilder()->submitForm() cycle —
 * unlike WebformRankingKernelTestBase's helpers (which call
 * validateWebformRanking() directly, sidestepping FormBuilder entirely) —
 * because what's under test here
 * (WebformSubmissionConditionsValidator's generic post-submission
 * data-clearing pass) only runs as part of the real form pipeline, not our
 * own element's own validation logic.
 *
 * Scope note: '#states_clear' => FALSE (preserving a pre-existing value on
 * an *edit* of an already-submitted entry, rather than clearing it) is
 * deliberately not covered here. That check happens entirely inside
 * WebformSubmissionConditionsValidator::processFormRecursive(), gated only
 * on the '#states_clear' property itself — it never inspects the element's
 * value shape, so there's nothing element-type-specific for this module to
 * verify. It was investigated directly (manually driving a hand-built edit
 * FormState through the real pipeline): the generic mechanism honors the
 * flag correctly. Simulating that scenario reliably in a Kernel test
 * requires a real HTTP-driven edit-form round trip (checkbox
 * default-value resolution against a hand-built FormState for an *edit*
 * has known subtleties BrowserTestBase's real request lifecycle doesn't
 * hit) — not worth the fragility for a code path this module doesn't
 * implement.
 */
#[Group('webform_ranking')]
class WebformRankingElementStatesTest extends WebformRankingKernelTestBase {

  /**
   * A hidden element's stale/forged submitted value never reaches storage.
   *
   * Simulates a real-world case a hostile or simply out-of-sync client
   * could produce: the ranking element is server-side hidden (trigger
   * unchecked) but the raw POST still carries rank selections for it
   * (browsers don't strip hidden fields from a submission; a forged
   * request could send anything at all). Confirmed here specifically
   * because our composite element's stored shape (a flat item-value =>
   * rank map, i.e. an array) needs to correctly hit
   * WebformSubmissionConditionsValidator::processFormRecursive()'s
   * `is_array($data[$key]) ? [] : ''` branch, not just be "empty" for
   * some element-type-specific reason of our own.
   */
  public function testHiddenElementValueIsClearedOnSubmit(): void {
    $webform = $this->createWebformWithElements('states_clear_default', [
      'trigger' => [
        '#type' => 'checkbox',
        '#title' => 'Show ranking?',
      ],
      'ranking' => [
        '#type' => 'webform_ranking',
        '#title' => 'Ranking',
        '#ranking_style' => 'matrix',
        '#items' => [
          ['value' => 'a', 'label' => 'Item A'],
          ['value' => 'b', 'label' => 'Item B'],
        ],
        '#states' => [
          'visible' => [':input[name="trigger"]' => ['checked' => TRUE]],
        ],
      ],
    ]);

    $data = $this->submitAndGetData($webform, [
      'trigger' => 0,
      'ranking' => ['matrix' => ['a' => '1', 'b' => '2']],
    ]);

    $this->assertSame([], $data['ranking']);
  }

  /**
   * Drives a real new submission through FormBuilder.
   *
   * @param \Drupal\webform\WebformInterface $webform
   *   The webform being submitted.
   * @param array $input
   *   Raw user input, keyed by webform element key.
   *
   * @return array
   *   The saved submission's data.
   */
  protected function submitAndGetData(WebformInterface $webform, array $input): array {
    $submission = WebformSubmission::create(['webform_id' => $webform->id()]);

    $form_object = \Drupal::entityTypeManager()->getFormObject('webform_submission', 'add');
    $form_object->setEntity($submission);

    $form_state = new FormState();
    $form_state->setUserInput($input + ['op' => 'Submit']);
    $form_state->setRequestMethod('POST');
    $form_state->setProgrammed(TRUE);
    $form_state->setProgrammedBypassAccessCheck(FALSE);

    \Drupal::formBuilder()->submitForm($form_object, $form_state);
    $this->assertSame([], $form_state->getErrors(), 'Submission unexpectedly failed validation.');

    return $form_object->getEntity()->getData();
  }

}
